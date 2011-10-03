<?php

/**
 * Mplate_Taglib_Core_Compiler
 *
 * Core tag library functions. Contains template functions that are integral 
 * to Mplate's template language
 *
 * @package		mplate
 * @subpackage	mplate.core
 *
 */
class Mplate_Taglib_Core_Compiler extends Mplate_Taglib
{
    function _if($attrs, $compiler=Mplate::compiler, $content=Mplate::blockContent) 
    {
        if($content!==null) 
        {
            echo "<?php if ($attrs): ?>";
            echo $content;
            echo "<?php endif; ?>";
            
        }
    }
    function _else($attrs, $compiler=Mplate::compiler)
    {
        echo "<?php else: ?>";
    }
    const foreachelse = "__MPLATE_FOREACHELSE_";
    function _foreach($attrs, $compiler=Mplate::compiler, $content=Mplate::blockContent)
    {
        if($content!==null)
        {
            /* remove outer brackets, if present */
            $foreachArgs = preg_replace('~^\s*\(?(.*?)\)?\s*$~', "\\1", $attrs);
            
            /* only include if clause if there was a foreachelse */
            if(strpos($content, self::foreachelse)===false)
            {
                echo "<?php foreach($foreachArgs): ?>";
                echo $content;
                echo "<?php endforeach; ?>";
            }
            else
            {
                list($loopvar, $rest) = preg_split('~\s+~', $foreachArgs, 2);
                echo "<?php if($loopvar): foreach($foreachArgs): ?>";
                echo str_replace(self::foreachelse, "<?php endforeach; else: ?>", $content);
                echo "<?php endif; ?>";
            }
        }
    }
    function foreachelse($attrs, $compiler=Mplate::compiler)
    {
        echo self::foreachelse;
    }
    function ld($compiler=Mplate::compiler)
    {
        echo $this->mplate->ldelim();
    }
    function rd($compiler=Mplate::compiler)
    {
        echo $this->mplate->rdelim();
    }
    function lde($compiler=Mplate::compiler)
    {
        echo $this->mplate->ldelim(false);
    }
    function rde($compiler=Mplate::compiler)
    {
        echo $this->mplate->rdelim(false);
    }
    function capture($attrs, $compiler=Mplate::compiler, $content=Mplate::blockContent)
    {
        if($content===null)
        {
            echo "<?php ob_start(); ?>";
        }
        else
        {
            echo $content;
            $attrs = $compiler->parseAttrs($attrs);
            if(isset($attrs['assign']))
            {
                echo "<?php $attrs[assign] = ob_end_clean(); ?>";
            }
            else
            {
                throw new Mplate_CompilerException("'assign' attribute not found when compiling capture tag");
            }
        }
    }
    /**
      * Strips leading and trailing whitespace on a line, as well as empty lines
      */
    function strip($attrs, $compiler=Mplate::compiler, $content=Mplate::blockContent)
    {
        if($content!==null)
        {
            //echo preg_replace("~(>)\s+|\s+(<)~",  "\\1\\2", $content);
            echo preg_replace('~(^\s+)|(\s+$)~m',  '', $content);
        }
    }
    //todo
    function _include($attrs, $compiler=Mplate::compiler)
    {   
        $attrs = $compiler->parseAttrs($attrs);
        if(isset($attrs['file']))
        {
            $file = $compiler->codeToValue($attrs['file']);
            $fn = $compiler->mplate->viewfile($file);
            #echo "<" . "?php include '$fn'; ?".">";
        }
        else
        {
            throw new Mplate_CompilerException("'file' attribute not found when compiling include tag");
        }   
    }
    /**
     * Includes the source of a different template file and compiles it in place
     */
    function inline($attrs, $compiler=Mplate::compiler)
    {
        $attrs = $compiler->parseAttrs($attrs);
        if(isset($attrs['file']))
        {
            $fn = $compiler->codeToValue($attrs['file']);
            
            echo $compiler->compileString(file_get_contents($compiler->templatePath($fn)));
        }
        else
        {
            throw new Mplate_CompilerException("'file' attribute not found when compiling inline tag");
        }
    }
    function raw($attrs, $compiler=Mplate::compiler)
    {
        $expression = $compiler->mangleVars($attrs);
        echo "<?php echo $expression; ?>";
    }
    function wrap($attrs, $compiler=Mplate::compiler, $content=Mplate::blockContent)
    {
        if($content!==null)
        {
            //assign to $attrs['varname'], set var, compile file
        }
    }
}