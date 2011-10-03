<?php

/* todo: generalise with other frameworks, move to own file in subdirectory */

class Mplate_FrameworkHelperTaglib extends Mplate_Taglib
{
    protected function compileHelperMethodCall(Mplate_Compiler $compiler, $func, $attrs, $functionInfo, $objCode)
    {
        $attrs = $compiler->parseAttrs($attrs);
        $helper = $functionInfo[$func];
        if($helper)
        {
            //match named argument to right spot in the helper argument list.
            $args = array();
            foreach($helper as $i => $arg)
            {
                if(isset($attrs[$arg]))
                {
                    $args[$i] = $attrs[$arg];
                }
                else
                {
                    $args[$i] = "null";
                }
            }
            
            //drop null values at end of argument list
            for($i=count($args)-1;$i!=0;$i--)
            {
                if($args[$i]==="null")
                {
                    unset($args[$i]);
                }
                else
                {
                    break;
                }
            }
            
        }
        else
        {
            return false;
        }
        //assert: args now contains PHP expressions for every function argument
        $argString = join(",", $args);
        echo "<?php echo {$objCode}->{$func}({$argString}); ?>";
        return true;
    }
}

class Mplate_Taglib_Zend extends Mplate_FrameworkHelperTaglib
{
    public function __compile(Mplate_Compiler $compiler, $func, $attrs, $traits)
    {
        $zendHelpers = array(
            'formText' => array('name', 'value', 'attr'),
            'formPassword' => array('name', 'value', 'attr'),
            'formSubmit' => array('name', 'value', 'attr'),
            'formReset' => array('name', 'value', 'attr'),
            'htmlList' => array('item', 'ordered', 'attr', 'escape'),
        );
        return $this->compileHelperMethodCall($compiler, $func, $attrs, $zendHelpers, "\$this");
    }
}