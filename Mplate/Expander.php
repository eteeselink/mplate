<?php

class Mplate_ObjectRegistry
{
    static protected $objects;
    static public function get($className, $args=array())
    {
        if(!isset(self::$objects[$className]))
        {
            $rc = new ReflectionClass($className);
            self::$objects[$className] = $rc->newInstanceArgs($args);
        }
        return self::$objects[$className];
    }
}

/**
 * Internally used by Mplate to store information about a function or method in a taglib
 */
class Mplate_Callable
{
    public function call($objArgs, $callArgs)
    {
        if($this->isMethod)
        {
            $callback = array(Mplate_ObjectRegistry::get($this->className, $objArgs), $this->callName);
        }
        else
        {
            $callback = $this->callName;
        }
        return call_user_func_array($callback, $callArgs);
    }
}

class Mplate_Expander implements ArrayAccess, IteratorAggregate
{
    static protected $instances;
    
    protected $mainClassName;
    protected $RO;
    protected $dir;
    protected $filename;
    protected $baseFilename;
    
    protected $callables;
    
    public $obj;
    public $name;
    
    /* factory method */
    public static function instance(Mplate_Expandable $obj, $self="Mplate_Expander")
    {
        $RO = new ReflectionObject($obj);
        $className = $RO->getName();
        if(!isset(self::$instances[$className]))
        {
            self::$instances[$className] = new $self($className, $RO, $obj);
        }
        return self::$instances[$className];
    }

    
    protected function addFunction($filename, $name, $callName)
    {
        if(function_exists($callName))
        {
            //assert: function_exists($callName)
            $c = new Mplate_Callable();
            $c->path = $filename;
            $c->isMethod = false;
            $c->className = null;
            $c->name = $name;
            $c->callName = $callName;
            $c->rf = new ReflectionFunction($c->callName);
            $c->rc = null;
            $this->callables[$c->name] = $c;
        }
    }
    protected function addClass($filename, $className)
    {
        
        if(class_exists($className, false))
        {
            //assert: class_exists($className)
            
            $RC = new ReflectionClass($className);
            foreach($RC->getMethods() as $RM)
            {
                $c = new Mplate_Callable();
                $c->path = $filename;
                $c->isMethod = true;
                $c->className = $className;
                $c->name = $RM->getName();
                $c->callName = $c->name;
                $c->rf = new ReflectionMethod($c->className, $c->callName);
                $c->rc = new ReflectionClass($c->className);
                $this->callables[$c->name] = $c;
            }
        }
    }
    /* attempts to find expandable classes or functions in a subdirectory named after the main class */
    /* todo: recurse */
    protected function inspectDir()
    {
        $mainClassName = $this->mainClassName;
        
        // see if a subdir named after the php file exists. we try an all-lowercased and a first-uppercased
        // directory as well, to allow slightly different package conventions.
        $this->subDir = "{$this->dir}/{$this->baseFilename}";
        //echo $this->subDir; 
        if(!is_dir($this->subDir))
        {
            $this->subDir = $this->dir . '/' . ucfirst($this->baseFilename);
            if(!is_dir($this->subDir))
            {
                $this->subDir = $this->dir . '/' . strtolower($this->baseFilename);
                if(!is_dir($this->subDir))
                {
                    $this->subDir = null;
                }
            }
        }
        
        
        if($this->subDir)
        {
            
            $filenames = glob($this->subDir."/*.php");
            

            
            $this->callables = array();
            foreach($filenames as $filename)
            {
                $basename = basename($filename);//str_replace("\\","/", $filename);
           
                //convention: if it starts with a lowercase character, it contains a function.
                if(preg_match('~^([a-z_].*)\.php$~', $basename, $m))
                {
                    //function in template equals the filename without .php
                    $name = $m[1];

                    //actual function name is namespaced with class, in lowercase, i.e.
                    //mplate_taglib_plugin_showSmiley
                    $callName = strtolower($mainClassName)."_".$name;
                    
                    include_once($filename);
                    $this->addFunction($filename, $name, $callName);
                }
                
                //convention: it is a class that we want to merge if it starts with an uppercase
                //character.
                elseif(preg_match('~^([A-Z].*)\.php$~', $basename, $m))
                {
                    include_once($filename);
                    $className = $mainClassName."_".$m[1];
                    
                    $this->addClass($filename, $className);
                }
            }
        }    
    }
    protected function __construct($mainClassName, $RO, $obj)
    {
        $this->mainClassName = $mainClassName;
        $this->RO = $RO;
        $this->obj = $obj;
        $this->filename = $RO->getFileName();
        $this->baseFilename = basename($this->filename, ".php");
        $this->dir = realpath(dirname($this->filename)); //includes trailing slash on php5.
        $this->name = $this->obj->name;

        //we use the name of the PHP file containing the main class to see if there's a subdirectory
        //with expandable files and to guess a taglib name if none was given.        
        
        
        //no name was set by the object, so we use the filename for default. this is generally preferred anyway.
        if($this->name===null)
        {
            $this->name = strtolower($this->baseFilename);
        }
        
        $this->inspectDir();
        
        $this->addClass($RO->getFileName(), $mainClassName); 
        //NOT ANYMORE: no filename, we assume the main class is explicitly loaded by the programmer.
        
        
        //$this->callables now contains all found functions and methods, mapped by a unique string.
    }
    public function offsetExists ($offset) 
    {
        return ($this->offsetGet($offset) !== null);
    }
    public function offsetGet ($offset) 
    {
        //add default support
        if(isset($this->callables[$offset]))
        {
            return $this->callables[$offset];
        }
        else
        {
            return null;
        }
    }
    public function offsetSet ($offset, $value) 
    {
        return $value; //no setting.
    }
    public function offsetUnset ($offset) 
    {
        return;
    }

    public function getIterator()
    {
        return new ArrayIterator($this->callables);
    }

}