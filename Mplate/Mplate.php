<?php

if(!defined('DS')) define('DS',DIRECTORY_SEPARATOR);


interface Mplate_Expandable
{
    /*
     * Every taglib must implement this interface. The expandTaglib is only called upon
     * compilation, so may contain compiler-dependent code.
     */
    public function expandTaglib();
}

/**
 * Compiler callbacks
 *
 * Implement this interface in an object registered to Mplate to 
 * launch events before or after a file is being compiled.
 *
 * TODO: make this work, like, at all.
 */
interface Mplate_CompileListener
{
    public function beforeCompile(&$templateCode);
    public function afterCompile(&$compiledCode);
}



class Mplate_Taglib implements Mplate_Expandable
{
    protected $mplate;
    public $name;

    public function expandTaglib()
    {
        return Mplate_Expander::instance($this);
    }
    
    public function __construct(Mplate_Base $mplate, $name = null)
    {
        $this->mplate = $mplate;
        if($name!==null)
        {
            $this->name = $name;
        }
    }
}



class Mplate_Base
{
    protected $taglibs;
    public $settings;
    
    const blockContent  = 0x001;
    const setsVariables = 0x002;
    const mplate        = 0x003;
    const compiler      = 0x004;
    const blockInfo     = 0x005;
    const attrs         = 0x006;
    
    const escapeXHTML   = 0x101;
    const escapeXML     = 0x102;
    const escapeNONE    = 0x103;
    
    /**
     * @param string $fn The filename  of a template file, relative to the template directory
     * @return An absolute path to $fn
     */
    public function templatePath($fn)
    {
        return $this->settings['templateDir'].DS.$fn;
    }
    /**
     * @param string $fn The filename of a template file, relative to the template directory
     * @return An absolute path to the compiled php source file of $fn
     */
    public function compilePath($fn)
    {
        return $this->settings['compileDir'].DS.$fn.".php";
    }
    public function registerTaglib(Mplate_Expandable &$obj)
    {
        $this->taglibs[] =& $obj;
    }

}

class Mplate_Exception extends Exception
{
    public function __construct($s, $e=null)
    {
        if($e!==null)
        {
            $s .= ": " . get_class($e) . "('".$e->getMessage()."')";
        }
        parent::__construct("Mplate exception: ".$s);
    }
}

class Mplate_Mplate extends Mplate_Base
{
    /**
     * @var array An associative array that should contain the variables that you wish to 
     * expose to the view template. Assign to it like you would to any other array.
     */
    public $vars;
    
    /**
     * @var object A buffer used by template block functions that set variables. There is 
     * usually no point in assigning values to this variable.
     */
    //public $variableBuffer;
    //public $expandedObjects;
    //public $blockStack;

    protected $context;
    
    public function __construct(Mplate_ContextInterface $context=null)
    {
        $here = dirname(__FILE__);
        $this->settings = array(
            "compileDir"                => $here.DS.'compiled',
            "templateDir"               => $here.DS.'templates',
            "forceCompile"              => false,
            "mplateVariableName"         => '__mplate',
        );
        $this->vars = array();
        $this->variableBuffer = array();
        
        if($context===null)
        {
            $this->context = new Mplate_Context();
        }
        else
        {
            $this->context = $context;
        }
    }
    
    protected function getCompiler()
    {
        require_once("Compiler.php");
        $c = new Mplate_Compiler($this);
        $c->__cloneProperties(get_object_vars($this));
        return $c;
    }
    
    protected function needCompile($templateFn, $compiledFn)
    {
        if(($this->settings['forceCompile']) || (!file_exists($compiledFn)))
        {
            return true;
        }
        else
        {
            return (filemtime($templateFn) >= filemtime($compiledFn));
        }
    }
    
    protected function compile($templateFn, $compiledFn)
    {
        $compiler = $this->getCompiler();
        return $compiler->compileFile($templateFn, $compiledFn);
    }
    
    protected function getCompiledFn($templateFn)
    {
        $compiledFn = $this->compilePath($templateFn);
        $templateFn = $this->templatePath($templateFn);
        if(file_exists($templateFn))
        {
            if($this->needCompile($templateFn, $compiledFn))
            {
                $this->compile($templateFn, $compiledFn);
            }
            return $compiledFn;
        }
        else
        {
            throw new Mplate_Exception("template file '$templateFn' not found!");
        }
    }
    
    protected function run($templateFn, $captureOutput)
    {
        $compiledFn = $this->getCompiledFn($templateFn);
        
        $this->vars[$this->settings["mplateVariableName"]] = $this;
        
        //FIXME: errmsg if no file
        if($captureOutput)
        {
            ob_start();
            //FIXME: remove //.
            $this->runView($compiledFn);
            $output = ob_get_contents();
            ob_end_clean();

            return $output;
        }
        else
        {
            $this->runView($compiledFn);
        }
        return null;
    }
    
    protected function runView($fn)
    {
        $this->context->runMplateView($fn, $this->vars);
    }
    
    /**
     * Sets the context object in which the view is to be ran. 
     *
     * @param Mplate_ContextInterface $context A class that implements 
     * Mplate_ContextInterface (f.ex. a class derived from Mplate_Context).
     */
    public function setContext(Mplate_ContextInterface $context)
    {
        $this->context = $context;
    }
    
    /**
     * Gets the file name corresponding to the specified template file and compiles
     * the template first if necessary. Intended for plain-and-simple interfacing; 
     * just set the variables you wish to expose to the view and run 
     * 
     * <code>require $mplate->viewfile("myview.tpl");</code>
     *
     * @param string $templateFn file name of the view template
     */
    public function viewfile($templateFn)
    {
        return $this->getCompiledFn($templateFn);
    }
    
    /**
     * Run the template with the variables set in $vars and return output.
     * Compiles the template first if necessary.
     *
     * @param string $templateFn file name of the view template
     * @return string The (HTML) output produced by the template
     */
    public function fetch($templateFn)
    {
        return $this->run($templateFn, true);
    }
    
    /**
     * Run the template with the variables set in $vars and echo output.
     * Compiles the template first if necessary.
     *
     * @param string $templateFn file name of the view template
     */
    public function display($templateFn)
    {
        $this->run($templateFn, false);
    }
    
    /**
     * Return the PHP code corresponding to the specified template file.
     * Compiles the template first if necessary.
     */
    public function fetchPHP($templateFn)
    {
        return file_get_contents($this->getCompiledFn($templateFn));
    }
}

/**
 * Mplate context interface. Provided only because of PHP's lack of multiple
 * inheritance. If you want to make your own context class, either derive
 * from Mplate_Context, or implement this interface. 
 */
interface Mplate_ContextInterface
{
    /*
     * Accepts two parameters, which are unnamed in order to keep the view's context
     * completely empty. The first parameter is the filename of the view php file
     * and the second parameter is an array containing all view variables that have been
     * set.
     *
     * It is recommended to include only the following code:
     *
     *   extract(func_get_arg(1));
     *   include func_get_arg(0);
     */
    public function runMplateView();
}
/**
 * Empty implementation of Mplate_ContextInterface.
 */
class Mplate_Context implements Mplate_ContextInterface
{
    public function runMplateView()
    {
        extract(func_get_arg(1));
        include func_get_arg(0);
    }
}