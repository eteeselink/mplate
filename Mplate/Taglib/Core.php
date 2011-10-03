<?php

class Mplate_Taglib_Core extends Mplate_Taglib
{
    public $name = "c";
    function assign($attrs, &$vars=Mplate::setsVariables)
    {
        $vars[$attrs['name']] = $attrs['value'];
    }
    function repeat($attrs, $content=Mplate::blockContent, &$vars=Mplate::setsVariables, &$info = Mplate::blockInfo)
    {
        
    }
}