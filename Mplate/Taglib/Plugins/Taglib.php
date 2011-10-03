<?php


class Mplate_Taglib_Plugins_Taglib extends Mplate_Taglib
{
    public $name = "p";
    public function test($attrs, $compiler=Mplate::compiler, $content=Mplate::blockContent)
    {
        echo "hmm lekker koffie.";
    }
}