<?php

require_once("../Mplate.php");
$mplate = new Mplate();

$mplate->settings["templateDir"] = dirname(__FILE__);
$mplate->settings["compileDir"] = dirname(__FILE__)."/compiled";
$mplate->settings["forceCompile"] = true;

$actors = array();
$d = new DateTime("13:37",timezone_open('Europe/Moscow'));

if($d->format("U") < time())
{
    $first = array("Richard Dean", "David", "John", "Hulk");
    $last = array("Anderson", "Hasselhoff", "Candy", "Hogan");
    shuffle($first);
    shuffle($last);
    $a = array_combine($first, $last);
    foreach($a as $k => $v) 
    {
        $actors[] = array(
            "name" => "$k $v",
            "friendly" => abs(crc32("$k $v")%20)
        );
    }
}
$mplate->vars['actors'] = $actors;

$mplate->display('actors.tpl');