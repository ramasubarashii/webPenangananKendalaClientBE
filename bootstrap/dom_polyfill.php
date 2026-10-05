<?php

if (!function_exists('mb_split')) {
    function mb_split($pattern, $string, $limit = -1) {
        // Unescape backslashes if passed like \s+
        $pattern = str_replace('\\\\', '\\', $pattern);
        return preg_split('/' . $pattern . '/u', $string, $limit);
    }
}

if (!class_exists('DOMDocument')) {
    class DOMNodeList implements \Countable, \IteratorAggregate {
        public $nodes = [];
        public function __construct($nodes = []) {
            $this->nodes = $nodes;
        }
        public function item($index) {
            return $this->nodes[$index] ?? null;
        }
        public function count(): int {
            return count($this->nodes);
        }
        public function getIterator(): \Traversable {
            return new \ArrayIterator($this->nodes);
        }
    }

    class DOMNode {
        public $nodeName = 'div';
        public $nodeValue = '';
        public $nodeType = 1;
        public $textContent = '';
        public $childNodes;
        public $attributes;
        public $parentNode = null;
        public $previousSibling = null;
        public $nextSibling = null;
        public $firstChild = null;
        public $lastChild = null;

        public function __construct() {
            $this->childNodes = new DOMNodeList([]);
            $this->attributes = new DOMNodeList([]);
        }

        public function getAttribute($name) {
            return '';
        }
        public function hasAttribute($name) {
            return false;
        }
        public function hasChildNodes() {
            return count($this->childNodes) > 0;
        }
    }

    class DOMElement extends DOMNode {}
    class DOMText extends DOMNode {
        public function __construct($val = '') {
            parent::__construct();
            $this->nodeName = '#text';
            $this->nodeValue = $val;
            $this->nodeType = 3;
            $this->textContent = $val;
        }
    }

    class DOMDocument extends DOMNode {
        public function loadHTML($html, $options = 0) {
            return true;
        }

        public function getElementsByTagName($name) {
            $child = new DOMElement();
            $child->nodeName = 'div';

            $body = new DOMElement();
            $body->nodeName = 'body';
            $body->childNodes = new DOMNodeList([$child]);

            return new DOMNodeList([$body]);
        }
    }
}
