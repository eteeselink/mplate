<?php

require_once("Expander.php");


class Mplate_CompilerException extends Mplate_Exception
{
    public function __construct($s, $parseInfo=null, $e=null)
    {
        if($parseInfo!==null)
        {
            $s .= " at line {$parseInfo->line} in {$parseInfo->templateFn}";
        }
        parent::__construct($s, $e);
    }
}

class Mplate_ParseInfo
{
    public $line;
    public $templateFn;
    public function __construct($templateFn)
    {
        $this->line = 1;
        $this->templateFn = $templateFn;
    }
}

class Mplate_Compiler extends Mplate_Base
{
    public $mplate;                      //Mplate object reference
    protected $literals;                 //assoc array: maps literal strings and code blocks from index to actual content
    
    protected $templateBlockLevel;       //level of template block function in ${$mplateVar}->blockStack
    protected $compilerBlockAttrStack;   //compiler block function attributes
    protected $blockTagStack;            //contains block tags "currently" started but not ended yet
    
    protected $currentRun;
    protected $parseInfo;
    protected $dependencies;             //keeps track of all PHP files we need to include
    protected $mplateVar;                //name of variable containing the Mplate object
    protected $compiledFn;
    protected $templateFn;
    
    /**
     * @param boolean $func True to return the left delimiter for functions, 
     * false to return the left delimiter for expressions.
     *
     * @return The left delimiter character, such as "{".
     */
    public function ldelim($func=true)
    {
        return $this->currentRun[$func ? 'funcLDelim' : 'exprLDelim'];
    }
    /**
     * @param boolean $func True to return the right delimiter for functions, 
     * false to return the right delimiter for expressions.
     *
     * @return The right delimiter character, such as "}".
     */
    public function rdelim($func=true)
    {
        return $this->currentRun[$func ? 'funcRDelim' : 'exprRDelim'];
    }
    /**
     * Constructor. The compiler gets instantiated by the Mplate object, and generally there should
     * be no need to create multiple compiler instances.
     */
    public function __construct($mplate)
    {
        $this->mplate = $mplate;
    }
    
    /**
     * Used internally to copy even protected properties from the Mplate object.
     * To be called only by an Mplate object.
     */
    public function __cloneProperties($props)
    {
        $props = array_intersect_key($props, get_object_vars($this));
        foreach($props as $k=>$v)
        {
            $this->$k = $v;
        }
        $this->compiler = $this;
        $this->initialize();
    }
    
    protected function initialize()
    {
        $this->setDefaults();
        $this->findTaglibs();
        $this->mplateVar = $this->settings["mplateVariableName"];
    }
    
    protected function setDefaults()
    {
        $defaults = array(
            "compiledFilePermissions"   => 0644,
            "dirPermissions"            => 0771,
            "smartyVarNotation"         => true,
            "escapeTarget"              => Mplate::escapeXHTML,
            "charset"                   => 'ISO8859-1',
            "checkForErrors"             => true,
            
            "delims" => 'mplate',
            "taglibDirs" => array(
                dirname(__FILE__)."/Taglib" => "Mplate_Taglib",
            ),
        );
        /*
         * Every run specifies a part of a template file that deserves special treatment.
         * You my specify a regex (pcre) to limit for which parts of a template file a certain run
         * should take place. All regexes in Mplate are delimted by '~' characters, so be sure to 
         * escape those as well, if they must be matched.
         *
         * Runs are processed in the order as they are in this array.
         */
        $delimDefaults = array(
            'mplate' => array(
                "scripts" => array(
                    "funcLDelim" => "#{",
                    "funcRDelim" => "}",
                    "exprLDelim" => "#{",
                    "exprRDelim" => "}",
                    "topNamespace" => "c",
                    "regex"  => "<script.*?>.*?</script>|<style.*?>.*?</style>",
                ),
                "default" => array(
                    "funcLDelim" => "{",
                    "funcRDelim" => "}",
                    "exprLDelim" => "{",
                    "exprRDelim" => "}",
                    "topNamespace" => "c",
                ),
            ),
            'jspish' => array(
                "default" => array(
                    "funcLDelim" => "<",
                    "funcRDelim" => ">",
                    "exprLDelim" => "#{",
                    "exprRDelim" => "}",
                ),
            ),
        );
        $this->settings = array_merge($defaults, $this->settings);
        if(!is_array($this->settings['delims']))
        {
        	$delims = $delimDefaults[$this->settings['delims']];
            if($delims)
            {
                $this->settings['delims'] = $delims;
            }
        }
    }
    
    /**
     * Compiles a string of code. If the compiler is currently compiling, the string is interpreted
     * as if it was present in the template code at the spot it was called. 
     *
     * If the compiler is not currently compiling, the string is interpreted as if it was the 
     * contents of a complete template file, and undergoes the complete compilation process.
     *
     * @param string $code A string of Mplate template code
     * @return string The PHP code that the template code was compiled into.
     */
    public function compileString($code)
    {
        //are we currently compiling?
        if($this->currentRun)
        {
            //yes, so we call compile() directly to compile the string with the currentRun's settings
            return $this->compile($code, false);
        }
        else
        {
            //no, so this is an outside custom mplate call, so we assume the code is a full template 
            //file contents
            return $this->compileFileContents($code);
        }
    }
    /**
     * Compiles a file. Called by the Mplate object, you generally do not need to call this method
     * directly.
     *
     * @param string $templateFn The filename of the template file
     * @param string $compiledFn The name of the file that the PHP source will be saved to
     * @return void
     */
    public function compileFile($templateFn, $compiledFn=null)
    {
        try
        {
            $code = file_get_contents($templateFn);
            $this->templateFn = $templateFn;
            $this->compiledFn = $compiledFn;
            
            $code = $this->compileString($code);
            
            return $this->writeCompiledFile($code);
        }
        catch (Mplate_Exception $e)
        {
            while(ob_get_level()) ob_end_clean();
            echo "<pre>".Mplate_Compiler::$debug."</pre>";
            trigger_error($e->getMessage(), E_USER_ERROR);
        }
        return "";
    }
    
    protected function findTaglibs()
    {   
        /* discover taglibs in taglib dir */
        
        foreach($this->settings['taglibDirs'] as $taglibDir => $prefix)
        {
            foreach(new DirectoryIterator($taglibDir) as $di)
            {
                $pathname = ((string)$di);
                
                if(substr($pathname, -4)==".php")
                {
                    $mainClassFn = "$taglibDir/$pathname";
                    
                    if(file_exists($mainClassFn))
                    {
                        include_once($mainClassFn);
                        $mainClassName = $prefix . "_" . basename($pathname, ".php");
                        
                        if((class_exists($mainClassName, false)) && (is_subclass_of($mainClassName, 'Mplate_Expandable')))
                        {
                            $this->registerTaglib(new $mainClassName($this));
                        }
                    }
                }
            }
        }
        
        
        /* give taglibs their names, by assigning keys to the taglib array */
        require_once("Expander.php");
        $t = $this->taglibs;
        
        $this->taglibs = array();
        if(!$t)
        {
            throw new Mplate_CompilerException("No taglibs found.");
        }
        foreach($t as &$obj)
        {
            $e = $obj->expandTaglib();
            
            //we do *not* overwrite taglib names. this way, the taglib registered earliest
            //with a key gets it. this allows users to manually override even the names of
            //taglibs that would be autodiscovered.
            if(!isset($this->taglibs[$e->name]))
            {
                $this->taglibs[$e->name] =& $obj;
            }
        }
        
    }

    protected function writeCompiledFile($code)
    {
        $compiledFnDir = dirname($this->compiledFn);
        if(!is_dir($compiledFnDir))
        {
            $r = mkdir($compiledFnDir, $this->settings['dirPermissions'], true);
            if(!$r) 
            {
                throw new Mplate_CompilerException("Could not create directory to write {$this->compiledFn}");
            }   
        }
        
        if(! ((is_writable($this->compiledFn)) || ((is_writable($compiledFnDir)) && (!file_exists($this->compiledFn)))) )
        {
            throw new Mplate_CompilerException("Could not write to {$this->compiledFn}");
        }
        file_put_contents($this->compiledFn, $code);
        @chmod($this->compiledFn, $this->settings['compiledFilePermissions']);
    }

    /**
     * performs a whole file compile, going through all the compiler runs
     */
    protected function compileFileContents($code)
    {
        $code = $this->annotateLineNumbers($code);
        $code = $this->markLiterals($code);
        
        
        foreach($this->settings['delims'] as $run)
        {
            $this->currentRun = $run;
            $this->parseInfo = new Mplate_ParseInfo($this->templateFn);
            $code = $this->compileCurrentRun($code);
        }
        $this->currentRun = null;
        $this->parseInfo = null;
        
        $code = $this->restoreLiterals($code);
        $code = $this->prependDependencies($code);
        
        $this->checkCompiledFile($code);
        
        $code = $this->removeLineNumberAnnotations($code);
        
        return $code;
    }
    protected function prependDependencies($code)
    {
        ob_start();
        if(isset($this->dependencies->classes)) 
        {
            foreach($this->dependencies->classes as $filename => $class)
            {
                echo "require_once('$filename'); \${$this->mplateVar}->expandedObjects['$class'] = new $class(\${$this->mplateVar});\n";
            }
        }
        if(isset($this->dependencies->functions)) 
        {
            foreach($this->dependencies->functions as $filename => $nothing)
            {
                echo "require_once('$filename');\n";
            }
        }
        $s = ob_get_clean();
        if($s)
        {
            return "<?php\n//including mplate expanded dependencies..\n".$s."?>".$code;
        }
        else
        {
            return $code;
        }
    }
    /**
     * Quotes its argument such that preg extended (modifier 'x') characters are also correctly quoted.
     */
    static protected function preg_quote_extended($s)
    {
        return str_replace(array("#"," "),array("\#","\ "), preg_quote($s, "~"));
    }
    static protected function textNestedParenRegex()
    {
        static $rr = 0;
        $rr++;
        return "[^()]*?  (?P<rr$rr> \( (?>[^()]+ | (?P>rr$rr) )*? \) )?  [^()]*? /?";
    }
    
    protected function updatePos($s)
    {
        /* marked literals may have been earlier-compiled snippets (from previous runs).
           the last line of those snippets has then been encoded into the literal string.
           if $s contains one or more such marked literal, we get the last one and extract
           the line number.
         */
        if(preg_match_all("~__MPLATE_LITERAL_(\d+)_(?P<l>\d+)_~s", $s, $m, PREG_OFFSET_CAPTURE|PREG_SET_ORDER))
        {
            $moo = array_pop($m);
            $lastLine = $moo['l'][0];
            $pos = $moo['l'][1];
            $s = substr($s, $pos);
            $this->parseInfo->line = $lastLine;
        }
        $this->parseInfo->line += substr_count($s, "\n");
    }
    
    //compiles code using settings in $this->currentRun
    protected function compile($templateCode, $markAsLiteral=true)
    {
        //compile code...
        
        $funcLDelim = $this->currentRun['funcLDelimQuoted'] = self::preg_quote_extended($this->currentRun['funcLDelim']);
        $funcRDelim = $this->currentRun['funcRDelimQuoted'] = self::preg_quote_extended($this->currentRun['funcRDelim']);
        $exprLDelim = $this->currentRun['exprLDelimQuoted'] = self::preg_quote_extended($this->currentRun['exprLDelim']);
        $exprRDelim = $this->currentRun['exprRDelimQuoted'] = self::preg_quote_extended($this->currentRun['exprRDelim']);
        
        
        if($this->currentRun['topNamespace'])
        {
            /* find functions that can be called without a namespace (top level functions,
            those in the core taglib) */
            $expander = $this->taglibs[$this->currentRun['topNamespace']]->expandTaglib();
            foreach($expander as $callable)
            {
                $m = preg_replace("~^(?:__construct|_)~","",$callable->name);
                if($m) $topFunctionNames[] = $m;            
            }
            $functionRegexes[] = join($topFunctionNames,'|');
        }

        /* find functions that start with a registered namespace */
        foreach(array_keys($this->taglibs) as $taglib)
        {
            $functionRegexes[] = preg_quote($taglib,"~").':\w+';
        }

        $functionRegexPrefix = "/? (?:".join("|",$functionRegexes).")";
        
        /* using recursive subpatterns, we support parentheses-nested expressions which may contain rdelims */
        $functionRegex = $functionRegexPrefix . self::textNestedParenRegex(); 

        /* make our two main regexes */
        $functionMatchRegex = "$funcLDelim (?P<f>$functionRegex) $funcRDelim";

        /* use negative lookahead to match any delimited text that does *not* correspond 
           to a function call */
        $expressionMatchRegex = "(?!$funcLDelim $functionRegexPrefix) $exprLDelim (?P<e>".self::textNestedParenRegex().") $exprRDelim";
        
        /* mark away all literals, such as quotes and friends */
        $templateCode = $this->markLiteralLiterals($templateCode);
        
        /* get rid of all comments */
        $templateCode = preg_replace("~$funcLDelim\*.*?$funcRDelim~s", "", $templateCode);


        /* Start parsing 
         *
         * We repeatedly search for a piece of text followed by either an expression or a function.
         * Using PCRE's named subpatterns, we see which of the two was found, and we find the offset
         * of the character immediately following the match. We repeat the search starting at that
         * character.
         *
         * We use PHP's output buffering because, frankly, why wouldn't we? It's fast and has nesting.
         */
       
        $this->templateBlockLevel = 0;
        $this->blockTagStack = array();

        ob_start();        
        $s = $templateCode;
        $r = "~^(?P<t>.*?) (?:$expressionMatchRegex|$functionMatchRegex) (?P<p>.?) ~isx";
        while($s!="")
        {
            if(preg_match($r, $s, $m, PREG_OFFSET_CAPTURE))
            {
                echo $m['t'][0];
                $this->updatePos($m['t'][0]);
                
                if($m['e'][1]!=-1)
                {
                    echo $this->compileExpression($this->markStrings($m['e'][0]));
                    $this->updatePos($m['e'][0]);
                }
                elseif($m['f'][1]!=-1)
                {
                    echo $this->compileFunction($this->markStrings($m['f'][0]));
                    $this->updatePos($m['f'][0]);
                }
                else
                {
                    echo "ERROR!";
                }
                $s = substr($s, $m['p'][1]);
            }
            else
            {
                echo $s;
                $this->updatePos($s);
                $s = "";
            }
        }
        
        $compiledCode = ob_get_clean();
        
        if($markAsLiteral)
        {
            //mark as literal so that it is not touched by subsequent runs.
            return $this->markLiteral($compiledCode, $this->parseInfo->line);
        }
        else
        {
            return $compiledCode;
        }
    }

    //compiles the current run, using the run's regex to specify which parts to compile.
    protected function compileCurrentRun($templateCode)
    {
        $this->parseInfo->line = 1;
        
        if(isset($this->currentRun['regex']))
        {
            //compile every block that is matched by this run's regex            
            $r = "~^(?P<t>.*?)(?P<r>{$this->currentRun['regex']})(?P<p>.?)~s";
            ob_start();
            while($templateCode!="")
            {
                if(preg_match($r, $templateCode, $m, PREG_OFFSET_CAPTURE))
                {
                    echo $m['t'][0];
                    $this->updatePos($m['t'][0]);
                    
                    /* assert: ($m['r'][1] != -1); */

                    echo $this->compile($m['r'][0]);
                    //$this->updatePos($m['r'][0]);

                    $templateCode = substr($templateCode, $m['p'][1]);
                }
                else
                {
                    echo $templateCode;
                    $templateCode = "";
                }
            }
            $compiledCode = ob_get_clean();
        }
        else
        {
            //no regex set in this run, so it happens for all code.
            $compiledCode = $this->compile($templateCode);
        }
        return $compiledCode;
    }
    protected function markLiteralLiterals($s)
    {
        //replace all {literal}..{/literal} blocks, in such a way that all of it except 
        //the tags themselves is restored.
        $lq = $this->currentRun['funcLDelimQuoted'];
        $rq = $this->currentRun['funcRDelimQuoted'];
        
        $s = preg_replace_callback("~{$lq}literal{$rq}(.*?){$lq}/literal{$rq}~s", array($this, 'markLiteral1'), $s);
        return $s;
    }
    protected function markStrings($s)
    {
        //build regex for quoted strings.
        $dq = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"';
        $sq = "'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'";
        $re = '(?:'.$dq.'|'.$sq.')';
        
        //replace all strings by mplate literals
        return preg_replace_callback("~{$re}~s", array($this, 'markLiteral0'), $s);
    }
    protected function markLiterals($s)
    {
        //find and replace all PHP code blocks
        $s = preg_replace_callback("~<\?php.*?\?>|<\?=.*\?>~s", array($this, 'markLiteral0'), $s);
        return $s;

    }
    protected function markLiteral0($m) { return $this->markLiteral($m[0]); }
    protected function markLiteral1($m) { return $this->markLiteral($m[1]); }
    protected function markLiteral($s, $endsAtLine="")
    {
        $index = count($this->literals);
        $this->literals[$index] = $s;
        return "__MPLATE_LITERAL_{$index}_{$endsAtLine}_";
    }
    //restores any found literals until a fixed point is reached.
    protected function restoreLiterals($s)
    {
        do
        {
            $s = preg_replace('~__MPLATE_LITERAL_(\d+)_(\d*)_~e', '\$this->literals[\\1]', $s, -1, $count);
        } while($count != 0);
        return $s;
    }
    //turns $moo.boink and friends into $moo['boink'], for us smarty addicts.
    public function mangleVars($arg)
    {
        if($this->settings['smartyVarNotation'])
        {
            return preg_replace_callback("~(?<!\\\\)\\$[\S]+~",array($this, 'mangleVar'), $arg);
        }
        else
        {
            return $arg;
        }
    }
    //in: string starting with $ and no whitespace.
    //out: variables with dots replaced by php [] notation.
    protected function mangleVar($var)
    {
        return preg_replace("~\.(\w+)~","['\\1']", $var[0]);
    }
    
    /**
     * Gets HTML-style attribute content from $str, and returns it as an array.
     * 
     * Note that all mplate attribute values are assumed to be valid PHP expressions, and
     * parts may be substituted by mplate placeholders. If you need the *value* of the
     * expression and not the PHP code that would make it, use codeToValue().
     *
     * @see codeToValue()
     *
     * @param string Attribute string
     * @return array Associative array with all attributes parsed.
     */
    public function parseAttrs($str)
    {
        if(preg_match_all('~([\w\.]+)=(\S+)~',$str, $m))
        {
            $attrs = array();
            foreach($m[1] as $i => $key)
            {
                //expand attribute names with dots into arrays
                $dimensions = explode(".", $key);
                $attr =& $attrs;
                while(count($dimensions))
                {
                    $k = array_shift($dimensions);
                    $attr =& $attr[$k];
                }
                
                //expand $moo.boink into $moo['boink'] in argument
                $attr = $this->mangleVars($m[2][$i]);
            }
            return $attrs;
        }
        else
        {
            return array();
        }
    }
    public function codeToValue($s)
    {
        $code = $this->restoreLiterals($s);
        return eval("return $code;");
    }
    protected function compileArray($a)
    {
        $elems = array();
        foreach($a as $k=>$v)
        {
            if(is_array($v))
            {
                $v = $this->compileArray($v);
            }
            if(is_string($k))
            {
                $k = "'$k'";
            }
            $elems[] = "$k=>$v";
        }
        return "array(".join(", ",$elems).")";
    }
    
    protected function compileFunction($s)
    {
        /* start values for traits object */
    	$traits = new stdClass();
        $traits->isStartTag     = true;
        $traits->isEndTag       = false;
        $traits->isCompiler     = false;
        $traits->isBlock        = false; //contains either false or argument position
        $traits->setsVariables  = false; //contains either false or argument position 
        $traits->wantsMplate    = false; //contains either false or argument position 
        $traits->wantsBlockInfo = false; //contains either false or argument position 
        $traits->wantsAttributes= 0;     //contains either false or argument position 
        
        /* find out if the tag is a {startTag}, an {/endTag} or {both/}. */
        
        if($s[0]=="/") 
        {
            $s = substr($s, 1);
            $traits->isEndTag = true;
            $traits->isStartTag = false;
        }
        elseif(substr($s,-1,1)=="/")
        {
            $s = substr($s, 0, -1);
            $traits->isEndTag = true;
        }
        
        //extract function name and attr string from function
        if(preg_match("~^([\w:]+)(.*)$~", $s, $m))
        {
        	array_shift($m);
            list($namespacedFunc, $attrs) = $m;
            $attrs = trim(preg_replace("~^\s*\((.*)\)\s*$~", '$1', $attrs, -1)); 
        }
        else
        {
            //this means something got through the scanner that we cannot parse.
            //-> implies rotten code :)
            throw new Mplate_CompilerException("Something went horribly wrong", $this->parseInfo);
        }
        
        
        /* separate namespace from function name */
        $funcParts = explode(":", $namespacedFunc, 2);
        
        //if a function is not prefixed with a namespace, prepend the default top namespace (defaults to "c")
        if(($this->currentRun['topNamespace']) && (count($funcParts)==1)) 
        {
            array_unshift($funcParts, $this->currentRun['topNamespace']);
        }
        list($ns, $func) = $funcParts;
        
        
        /* we now inspect the taglib object to make sure the function is called in the right way */
        $taglib =& $this->taglibs[$ns];
        if(!is_object($taglib))
        {
            throw new Mplate_CompilerException("Taglib object '$ns' not available.", $this->parseInfo);
        }
        else
        {
            $expander = $taglib->expandTaglib();
            
            /* we only create a reflection object if the taglib object actually exists. */
            $taglibRO = new ReflectionObject($taglib);
            $method = $func;
            
            /* check for availability of "func", "_func" methods */
            if(!isset($expander[$method]))
            {
                $method = "_".$method;
            }
        }
        
        if(isset($expander))
        {
            if(isset($expander[$method]))
            {
                /* 
                  method currently exists in a registered taglib. do more reflection to
                  find out how it wants to be called
                 */
                $callable = $expander[$method];
                
                $paramsRO = $callable->rf->getParameters();
                foreach($paramsRO as $i => $paramRO)
                {
                    if($paramRO->isDefaultValueAvailable())
                    {
                        switch($paramRO->getDefaultValue())
                        {
                        case self::blockContent:
                            $traits->isBlock = $i;
                            break;
                        case self::setsVariables:
                            $traits->setsVariables = $i;
                            break;
                        case self::compiler:
                            $traits->wantsMplate = $i;
                            $traits->isCompiler = true;
                            break;
                        case self::mplate:
                            $traits->wantsMplate = $i;
                            break;
                        case self::blockInfo:
                            $traits->wantsBlockInfo = $i;
                            break;
                        case self::attrs:
                            $traits->wantsAttributes = $i;
                            break;
                        }
                    }
                }
                
                if($traits->isBlock!==false)
                {
                    //make sure block tags are properly closed, opened, and nested
                    if($traits->isStartTag) 
                    {
                        $this->blockTagStack[] = $namespacedFunc;
                    }
                    if($traits->isEndTag)
                    {
                        $openTagFunc = array_pop($this->blockTagStack);
                        if($namespacedFunc!=$openTagFunc)
                        {
                            throw new Mplate_CompilerException("Closing tag '$namespacedFunc' does not have a corresponding opening tag (expected '$openTagFunc')", $this->parseInfo);
                        }
                    }
                }
                
                //assert: $traits is filled with all information we need in order to compile the function call.
                
                if($traits->isCompiler)
                {
                    $this->compileCompilerFunction($taglib, $callable, $attrs, $traits);
                }
                else
                {
                    $this->compileTemplateFunction($ns, $callable, $attrs, $traits);
                }  
                
            }
            else
            {
                
                if(isset($expander["__compile"]))
                {
                    $knownTraits = new stdClass();
                    $knownTraits->isStartTag     = $traits->isStartTag;
                    $knownTraits->isEndTag       = $traits->isEndTag;
                    $r = $expander["__compile"]->call(array($this), array($this, $func, $attrs, $knownTraits));
                    if($r===false)
                    {
                        throw new Mplate_CompilerException("function '$func' could not be __compile()d in taglib '$ns'.", $this->parseInfo);
                    }
                }
                else
                {
                    throw new Mplate_CompilerException("function '$func' not found in taglib '$ns'.", $this->parseInfo);
                }
            }
        }
        else
        {
            throw new Mplate_CompilerException("could not expand taglib '$ns' when searching for '$func'.", $this->parseInfo);
        }

    }
    
    /* compiles (runs) a compiler function */
    protected function compileCompilerFunction($taglib, $callable, $attrs, $traits)
    {
        $attrs = $this->mangleVars($attrs);
        /* we use output buffering's nesting to capture compiled blocks of code */
        if($traits->isBlock !== false)
        {
            
            if($traits->isStartTag)
            {
                $this->compilerBlockAttrStack[] = $attrs;
                ob_start();
                $content = null;
            }
            if($traits->isEndTag)
            {
                $content = ob_get_clean();
                $attrs = array_pop($this->compilerBlockAttrStack);
            }
            $args[$traits->isBlock] = $content;
        }
        if($traits->wantsMplate !== false)
        {
            $args[$traits->wantsMplate] = $this;
        }
        $args[$traits->wantsAttributes] = $attrs;
        ksort($args);
        
        $callable->call(array($this), $args);

        
    }

    //fills in empty "slots" in the array and builds an argument list.
    protected function buildArgList($args)
    {
        $keys = array_keys($args);
        $l = array_pop($keys);
        for($i=0; $i < $l; $i++)
        {
            if(!isset($args[$i])) $args[$i] = null;
        }
        ksort($args);
        return join(", ",$args);
    }
    /* creates php code that calls a template function */
    protected function compileTemplateFunction($ns, $callable, $attrs, $traits)
    {
        if($traits->wantsAttributes !== false)
        {
            $args[$traits->wantsAttributes] = $this->compileArray($this->parseAttrs($attrs));
        }
        
        if($callable->isMethod)
        {
            if($callable->path === null)
            {
                $functionCall = "\${$this->mplateVar}->taglibs['$ns']->{$callable->callName}";
            }
            else
            {
                $this->dependencies->classes[$callable->path] = $callable->className;
                $functionCall = "\${$this->mplateVar}->expandedObjects['{$callable->className}']->{$callable->callName}";
            }
        }
        else
        {
            $this->dependencies->functions[$callable->path] = true;
            $functionCall = $callable->callName;
        }
            
        $lines = array();
        /* before function call */
        if($traits->wantsMplate !== false)
        {
            $args[$traits->wantsMplate] = "\${$this->mplateVar}";
        }
        
        
        /* render function call */
        if($traits->isBlock===false) //render non-block function call
        {
            if($traits->setsVariables !== false)
            {
                $lines[] = "\${$this->mplateVar}->variableBuffer = array();";
                $args[$traits->setsVariables] = "\${$this->mplateVar}->variableBuffer";
            }
            $argString = $this->buildArgList($args);
            
            $lines[] = "{$functionCall}({$argString});";
            if($traits->setsVariables !== false)
            {
                $lines[] = "extract(\${$this->mplateVar}->variableBuffer);";
            }
        }
        else //render block function call
        {
            if($traits->isStartTag)
            {
                /* set argument list */
                if($traits->setsVariables !== false)
                {
                    $args[$traits->setsVariables] = "\${$this->mplateVar}->variableBuffer";
                }
                $args[$traits->isBlock] = "\${$this->mplateVar}->blockStack[{$this->templateBlockLevel}]['content']";
                if($traits->wantsBlockInfo !== false)
                {
                    $args[$traits->wantsBlockInfo] = "\${$this->mplateVar}->blockStack[{$this->templateBlockLevel}]['info']";
                    $lines[] = "\${$this->mplateVar}->blockStack[{$this->templateBlockLevel}]['info'] = null;";
                }
                $argString = $this->buildArgList($args);
                
                /* output code; function call in while loop, which might insert new variables on every run */
                $lines[] = "\${$this->mplateVar}->blockStack[{$this->templateBlockLevel}]['content'] = null;";
                $lines[] = "while({$functionCall}({$argString}))";
                $lines[] = "{ ";
                if($traits->setsVariables !== false)
                {
                    $lines[] = "  extract(\${$this->mplateVar}->variableBuffer);";
                    $lines[] = "  \${$this->mplateVar}->variableBuffer = array();";
                }
                $lines[] = "  ob_start();";

                $this->templateBlockLevel++;
            }
            if($traits->isEndTag)
            {
                /* output code; close while loop and possibly extract */
                $this->templateBlockLevel--;
                $lines[] = "  \${$this->mplateVar}->blockStack[{$this->templateBlockLevel}]['content'] = ob_get_clean();";
                $lines[] = "}";
                if($traits->setsVariables !== false)
                {
                    $lines[] = "extract(\${$this->mplateVar}->variableBuffer);";
                    $lines[] = "\${$this->mplateVar}->variableBuffer = array();";
                }
                $lines[] = "unset(\${$this->mplateVar}->blockStack[{$this->templateBlockLevel}]);";
            }
        }
        
        /* after function call */
        if(count($lines)>1)
        {
            echo "<?php\n  " . join($lines,"\n  ") . "\n?>";
        }
        else
        {
            echo "<?php " . $lines[0] . "?>";
        }
    }
    protected function compileExpression($expression)
    {
        $expression = $this->mangleVars($expression);
        return "<?php echo " . $this->escaper($expression) . "; ?>";
    }
    /**
     * Wraps a to-be-output expression with the correct escaping code for the current target
     *
     * @param string $code A PHP expression
     * @return string The PHP expression when properly escaped
     */
    public function escaper($code) 
    {
        switch ($this->settings['escapeTarget']) 
        {
            case Mplate::escapeXML:
            case Mplate::escapeXHTML:
                return "htmlspecialchars($code, ENT_QUOTES, '{$this->settings['charset']}')";
            default:
                return $s;
        }
    }

    protected function annotateLineNumbers($code)
    {
        if($this->settings['checkForErrors'])
        {
            $lines = explode("\n", $code);
            ob_start();
            $i = 1;
            foreach($lines as $line)
            {
                //put /*__MPLATE_L_<line>_*/ in front of every line
                //we put it *after* any leading whitespace so that f.ex. {strip} still works.
                echo preg_replace("~^([^\S\r]*)(.*)$~s", "\\1/*__MPLATE_L_{$i}_*/\\2\n", $line);
                $i++;
            }
            return ob_get_clean();
        }
        else
        {
            return $code;
        }
    }
    protected function removeLineNumberAnnotations($code)
    {
        if($this->settings['checkForErrors'])
        {
            return preg_replace("~/\*__MPLATE_L_\d+_\*/~", "", $code);
        }
        else
        {
            return $code;
        }
    }  
    /**
     * Tests the created code for parse errors
     */
    protected function checkCompiledFile($code)
    {
        if($this->settings['checkForErrors'])
        {
            /* 
              thanks for this trick to to kevin on 
              http://www.php.net/manual/en/function.php-check-syntax.php#82811
             */
            $code = "return true; ?>".$code;
            
            $errorStack = error_reporting( E_PARSE | error_reporting() );
            
            ob_start();
            $noErrors = eval( $code );
            $errorMessages = ob_get_clean();
            
            error_reporting($errorStack);
            
            if(!$noErrors)
            {
                //replace error message by correct compiled filename
                $re = '~'.preg_quote(__FILE__,"~").'\(\d+\) : eval\(\)\'d code~';
                $errorMessages = preg_replace($re, $this->compiledFn, $errorMessages);
                
                //report problem
                echo "Errors found in generated code! PHP said:";
                echo $errorMessages;
                
                //parse out line number, and find matching template file line number
                $foundLineNr = false;
                if(preg_match("~line\s+(\d+)~", strip_tags($errorMessages), $m))
                {
                    //minus one because line numbers start at 1 instead of 0
                    $compiledLineNr = $m[1] - 1;
                    $codeLines = explode("\n", $code);
                    
                    //if the line with the error has no line annotation, we iterate backwards until we find one.
                    while(!$foundLineNr && ($compiledLineNr >= 0))
                    {
                        if(preg_match('~/\*__MPLATE_L_(\d+)_\*/~', $codeLines[$compiledLineNr], $m))
                        {
                            $foundLineNr = $m[1];
                        }
                        $compiledLineNr--;
                    }
                }
                if($foundLineNr===false)
                {
                    echo "(sorry, cannot find corresponding line in template)";
                }
                else
                {
                    echo "(which corresponds to something near line <b>$foundLineNr</b> in <b>{$this->templateFn}</b>)";
                }
                
                die();
            }   
        }
    }
    
    static public $debug;
    static public function debug($s)
    {
        self::$debug .= "$s\n";
    }
}

