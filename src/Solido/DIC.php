<?php
/*
 * Based on Pimple, by SensioLabs
 *
 */

namespace Solido;

class DIC implements \ArrayAccess
{
    private $factories;
    private $protected;

    private $keys = array();
    private $values = array();

    public function __construct(array $values = array())
    {
        $this->reset();

        foreach ($values as $key => $value) {
            $this->offsetSet($key, $value);
        }

        return $this;
    }

    public function reset()
    {
        $this->keys = array();
        $this->values = array();

        $this->factories = new \SplObjectStorage();
        $this->protected = new \SplObjectStorage();

        return $this;
    }

    public function offsetSet($id, $value)
    {
        if (isset($this->frozen[$id])) {
            throw new \RuntimeException(sprintf('Cannot override frozen service "%s".', $id));
        }

        $capturedMethod = '_set_'.$id;
        $override = false;
        if (method_exists($this, $capturedMethod)) {
            $override = $this->$capturedMethod($value) === true;
        }

        if (!$override) {
            $this->values[$id] = $value;
            $this->keys[$id] = true;
        }

        return $this;
    }

    public function offsetGet($id)
    {
        $capturedMethod = '_get_'.$id;
        if (method_exists($this, $capturedMethod)) {
            return $this->$capturedMethod($id);
        }

        if (!isset($this->keys[$id])) {
            throw new \InvalidArgumentException(sprintf('Identifier "%s" is not defined.', $id));
        }

        if (
            isset($this->raw[$id])
            || !is_object($this->values[$id])
            || isset($this->protected[$this->values[$id]])
            || !method_exists($this->values[$id], '__invoke')
        ) {
            return $this->values[$id];
        }

        if (isset($this->factories[$this->values[$id]])) {
            return $this->values[$id]($this);
        }

        $raw = $this->values[$id];
        $val = $this->values[$id] = $raw($this);
        $this->raw[$id] = $raw;

        $this->frozen[$id] = true;

        return $val;
    }

    public function offsetExists($id)
    {
        return isset($this->keys[$id]);
    }

    public function offsetUnset($id)
    {
        if (isset($this->keys[$id])) {
            if (is_object($this->values[$id])) {
                unset($this->factories[$this->values[$id]], $this->protected[$this->values[$id]]);
            }

            unset($this->values[$id], $this->frozen[$id], $this->raw[$id], $this->keys[$id]);
        }

        return $this;
    }

    public function set($id, $value = null)
    {
        if (is_array($id)) {
            //$this->reset();
            foreach ($id as $key => $value) {
                $this->offsetSet($key, $value);
            }

            return $this;
        }

        return $this->offsetSet($id, $value);
    }

    public function get($id)
    {
        return $this->offsetGet($id);
    }

    public function uns($id)
    {
        return $this->offsetUnset($id);
    }

    public function __call($name, $args)
    {
        if (strpos($name, 'set') === 0) {
            return $this->__call_set(substr($name, 3), $args[0]);
        }
        if (strpos($name, 'get') === 0) {
            return $this->__call_get(substr($name, 3));
        }

        trigger_error('Call to undefined method '.__CLASS__.'::'.$name.'()', E_USER_ERROR);
    }

    public function __call_get($id)
    {
        $camelized = $this->camelize($id);

        return $this->offsetGet($camelized);
    }

    public function __call_set($id, $value)
    {
        $decamelized = $this->decamelize($id);

        return $this->offsetSet($decamelized, $value);
    }

    public function protect($callable)
    {
        if (!method_exists($callable, '__invoke')) {
            throw new \InvalidArgumentException('Callable is not a Closure or invokable object.');
        }

        $this->protected->attach($callable);

        return $callable;
    }

    public function raw($id)
    {
        if (!isset($this->keys[$id])) {
            throw new \InvalidArgumentException(sprintf('Identifier "%s" is not defined.', $id));
        }

        if (isset($this->raw[$id])) {
            return $this->raw[$id];
        }

        return $this->values[$id];
    }

    public function extend($id, $callable)
    {
        if (!isset($this->keys[$id])) {
            throw new \InvalidArgumentException(sprintf('Identifier "%s" is not defined.', $id));
        }

        if (!is_object($this->values[$id]) || !method_exists($this->values[$id], '__invoke')) {
            throw new \InvalidArgumentException(sprintf('Identifier "%s" does not contain an object definition.', $id));
        }

        if (!is_object($callable) || !method_exists($callable, '__invoke')) {
            throw new \InvalidArgumentException('Extension service definition is not a Closure or invokable object.');
        }

        $factory = $this->values[$id];

        $extended = function ($c) use ($callable, $factory) {
                    return $callable($factory($c), $c);
            };

        if (isset($this->factories[$factory])) {
            $this->factories->detach($factory);
            $this->factories->attach($extended);
        }

        return $this[$id] = $extended;
    }

    public function keys()
    {
        return array_keys($this->values);
    }

    public function register(ServiceProviderInterface $provider, array $values = array())
    {
        $provider->register($this);

        foreach ($values as $key => $value) {
            $this[$key] = $value;
        }

        return $this;
    }

    public function factory($callable)
    {
        if (!method_exists($callable, '__invoke')) {
            throw new \InvalidArgumentException('Service definition is not a Closure or invokable object.');
        }
        $this->factories->attach($callable);

        return $callable;
    }

    public static function camelize($text)
    {
        return preg_replace_callback('/(^|_)([a-z])/', function ($m) { return strtoupper($m[2]); }, $text);
    }

    public static function decamelize($text)
    {
        return preg_replace_callback('/(^|[a-z])([A-Z])/', function ($m) { return strtolower(strlen($m[1]) ? "{$m[1]}_{$m[2]}" : $m[2]); }, $text);
    }
}
