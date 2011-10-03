<?php


class Mplate_Taglib_Plugins extends Mplate_Taglib
{
    public $name = "p";
    public function test($attrs, $compiler=Mplate::compiler)
    {
        echo "hmm lekker koffie.";
    }
    /*public function __compile($compiler, $func, $attrs, $traits)
    {
        echo "MOO$func";
    }*/
}