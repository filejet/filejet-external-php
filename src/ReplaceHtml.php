<?php

namespace FileJet\External;

class ReplaceHtml
{
    const SOURCE_PLACEHOLDER = '#source#';
    const MUTATION_PLACEHOLDER = '#mutation#';
    const FILEJET_IGNORE_CLASS = 'fj-ignore';
    const FILEJET_FILL_CLASS = 'fj-fill';
    const FILEJET_INITIALIZED_CLASS = 'fj-initialized';

    const ATTRIBUTE_SRC = 'src';
    const ATTRIBUTE_SRCSET = 'srcset';

    /** @var string */
    private $urlPrefix;
    /** @var \DOMDocument */
    private $dom;
    /** @var string|null */
    private $basePath;
    /** @var string */
    private $secret;
    /** @var array */
    private $ignored = [];
    /** @var array */
    private $mutations = [];
    /** @var array */
    private $lazyLoaded = [];

    public function __construct(
        $storageId,
        $basePath = null,
        $secret = null
    )
    {
        $source = self::SOURCE_PLACEHOLDER;
        $mutation = self::MUTATION_PLACEHOLDER;
        $this->urlPrefix = "https://{$storageId}.5gcdn.net/ext/{$mutation}?src={$source}";
        $this->basePath = $basePath;
        $this->secret = $secret;
        $this->dom = new \DOMDocument();
    }

    public function removeUtf8Bom($text)
    {
        $bom = pack('H*','EFBBBF');
        $text = preg_replace("/^$bom/", '', $text);
        return $text;
    }
    
    public function replaceImages($content = null, array $ignored = [], array $mutations = [], array $lazyLoaded = [])
    {
        if (empty(trim($content))) {
            return '';
        }

        if ($content === strip_tags($content)) {
            return $content;
        }

        $this->ignored = $ignored;
        $this->mutations = $mutations;
        $this->lazyLoaded = $lazyLoaded;

        libxml_use_internal_errors(true);
        $this->dom->loadHTML(
            mb_convert_encoding($this->removeUtf8Bom($content), 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $this->replaceImageTags();
        $this->replacePictureTags();
        $this->replaceStyleBackground();
        return $this->dom->saveHTML();
    }

    public function prefixImageSource($originalSource)
    {
        $source = strpos($originalSource, $this->basePath) === 0
            ? $originalSource
            : "{$this->basePath}{$originalSource}";

        $parsed = parse_url($source);
        $path = implode('/', array_map('rawurlencode', explode('/', $parsed['path'])));
        $source = str_replace($parsed['path'], $path, $source);

        return str_replace(self::SOURCE_PLACEHOLDER, urlencode($source), $this->urlPrefix) . $this->signUrl($source);
    }

    private function replaceImageTags()
    {
        /** @var \DOMElement[] $images */
        $images = $this->dom->getElementsByTagName('img');
        $ignored = array_merge($this->ignored, [self::FILEJET_IGNORE_CLASS => self::FILEJET_IGNORE_CLASS, self::FILEJET_INITIALIZED_CLASS => self::FILEJET_INITIALIZED_CLASS]);

        foreach ($images as $image) {
            if (false === empty(array_intersect(explode(' ', ($class = $image->getAttribute('class')) ? $class : ''), $ignored))) {
                continue;
            }

            if (($image->parentNode->nodeType === XML_ELEMENT_NODE) && false === empty(array_intersect(explode(' ', ($class = $image->parentNode->getAttribute('class')) ? $class : ''), $ignored))) {
                continue;
            }

            /** DEFAULT */
            $this->handleSource($image);
            $this->handleSourceSet($image);
            $this->addLoadingLazy($image);

            foreach ($this->lazyLoaded as $attribute) {
                if(false === $image->hasAttribute($attribute)) {
                    continue;
                }
                $this->hasMultipleSources($image, $attribute) ? $this->handleSourceSet($image, $attribute) : $this->handleSource($image, $attribute);
            }
            $this->addInitializedClass($image);
        }
    }

    private function replacePictureTags()
    {
        /** @var \DOMElement[] $pictures */
        $pictures = $this->dom->getElementsByTagName('picture');
        $ignored = array_merge($this->ignored, [self::FILEJET_IGNORE_CLASS => self::FILEJET_IGNORE_CLASS, self::FILEJET_INITIALIZED_CLASS => self::FILEJET_INITIALIZED_CLASS]);

        foreach ($pictures as $picture) {
            if (false === empty(array_intersect(explode(' ', ($class = $picture->getAttribute('class')) ? $class : ''), $ignored))) {
                continue;
            }

            if (($picture->parentNode->nodeType === XML_ELEMENT_NODE) && false === empty(array_intersect(explode(' ', ($class = $picture->parentNode->getAttribute('class')) ? $class : ''), $ignored))) {
                continue;
            }

            foreach (iterator_to_array($picture->childNodes) as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                if ($child->nodeName !== 'source') continue;

                $this->handleSourceSet($child);
                $this->addInitializedClass($child);
            }

            $this->addInitializedClass($picture);
        }
    }

    private function handleSource($image, $attribute = self::ATTRIBUTE_SRC) {
        /** @var \DOMElement $image
         */
        $originalSource = $image->getAttribute($attribute);
        if ($this->isDataURL($originalSource)) {
            return;
        }
        if (strpos($originalSource, '.svg') !== false) {
            return;
        }

        $this->toAbsoluteUri($originalSource);

        $fill = false;
        if (strpos($image->getAttribute('class'), self::FILEJET_FILL_CLASS) !== false || (($image->parentNode->nodeType === XML_ELEMENT_NODE) && strpos($image->parentNode->getAttribute('class'), self::FILEJET_FILL_CLASS) !== false)) {
            $fill = true;
        }

        $height = $this->getHeight($image);
        $width = $this->getWidth($image);

        $imageClasses = explode(' ', ($class = $image->getAttribute('class')) ? $class : '');
        $customMutations = false === empty($imageClasses) ? array_intersect_key($this->mutations, array_flip($imageClasses)) : [];
        $prefixedSource = $this->prefixImageSource($originalSource);
        $image->setAttribute($attribute, $this->mutateImage($prefixedSource, $height, $width, $fill, $customMutations));
    }

    private function handleSourceSet($image, $attribute = self::ATTRIBUTE_SRCSET)
    {
        /** @var \DOMElement $image */
        $srcSet = $image->getAttribute($attribute);
        if (empty($srcSet)) {
            return;
        }

        $sources = explode(', ', $srcSet);
        $newSources = [];

        $imageClasses = explode(' ', ($class = $image->getAttribute('class')) ? $class : '');
        $customMutations = false === empty($imageClasses) ? array_intersect_key($this->mutations, array_flip($imageClasses)) : [];

        foreach ($sources as $source) {
            list($url, $w) = explode(' ', $source);
            
            if (strpos($w, 'w') !== false) {
                $widthAsInt = (int)$w;
                $customMutation = "resize_$widthAsInt";

                $prefixedSource = $this->prefixImageSource($url);
                $newUrl = $this->mutateImage($prefixedSource, null, null, false, array_merge($customMutations, [$customMutation]));
            } else {
                $prefixedSource = $this->prefixImageSource($url);
                $newUrl = $this->mutateImage($prefixedSource);
            }

            $newSources[] = "$newUrl $w";
        }

        $image->setAttribute($attribute, implode(', ', $newSources));
    }

    private function hasMultipleSources($image, $attribute)
    {
        /** @var \DOMElement $image */
        return count(explode(', ', $image->getAttribute($attribute))) > 1;
    }

    private function toAbsoluteUri(&$uri)
    {
        $parsed = parse_url($uri);
        if (empty($parsed['scheme'])) {
            $path = $parsed['path'] ?: '';
            $host = $_SERVER['HTTP_HOST'];
            if (strpos($uri, $host) !== false) {
                $path = substr(strstr($uri, $host), strlen($host));
            }
            $actual_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://$host";
            $uri = $actual_link . '/' . trim($path, '/');
        }
    }

    public function getAspectRatio($width, $height)
    {
        return $width > $height ? ($width / $height) : ($height / $width);
    }

    public function mutateImage($source, $height = null, $width = null, $fill = false, array $customMutations = [])
    {
        $mutation = 'auto';
        if (false === empty($customMutations)) {
            $mutation = implode(',', array_merge($customMutations, ['auto']));
        } else if (!empty($height) && empty($width)) {
            $mutation = 'resize_x' . $height . 'shrink,' . $mutation;
        } else if (empty($height) && !empty($width)) {
            $mutation = 'resize_' . $width . 'shrink,' . $mutation;
        } else if (!empty($height) && !empty($width)) {
            $mutation = $fill ? 'resize_' . $width . 'x' . $height . ',crop_' . $width . 'x' . $height . ',pos_center,fill_' . $width . 'x' . $height . ',bg_transparent,' . $mutation : "fit_$width" . 'x' . "$height," . $mutation;
        }
        return str_replace(self::MUTATION_PLACEHOLDER, $mutation, $source);
    }

    public function getWidth($image)
    {
        return $this->getDimension($image, ['width', 'min-width', 'max-width']);
    }

    public function getHeight($image)
    {
        return $this->getDimension($image, ['height', 'min-height', 'max-height']);
    }

    private function getDimension($image, array $dimensions)
    {
        /** @var \DOMElement $image */
        $style = $image->getAttribute('style');
        $style = stripslashes($style);
        $rules = explode(';', $style);

        foreach ($dimensions as $dimension) {
            $value = null;
            if (!empty($value = $image->getAttribute($dimension))) {
                return $value;
            }

            if (count($rules) === 0) {
                continue;
            }

            foreach ($rules as $rule) {
                if (strpos($rule, $dimension) !== false) {
                    if (strpos($rule, 'em') !== false
                        || strpos($rule, 'ex') !== false
                        || strpos($rule, 'rem') !== false
                        || strpos($rule, 'vw') !== false
                        || strpos($rule, 'vh') !== false
                        || strpos($rule, '%') !== false
                        || strpos($rule, 'ch') !== false
                    ) {
                        continue;
                    }
                    $dimensionValue = trim(str_replace('px', '', substr($rule, strpos($rule, ':') + 1)));
                    if (is_numeric($dimensionValue)) {
                        return $dimensionValue;
                    }

                }
            }
        }
        return null;
    }

    private function signUrl($url)
    {
        if ($this->secret === null) {
            return '';
        }
        return '&sig=' . hash_hmac('sha256', $url, $this->secret);
    }

    private function isDataURL($source)
    {
        return (bool)preg_match("/^\s*data:[^;]+(;base64)?/i", $source);
    }

    private function replaceStyleBackground()
    {
        $xpath = new \DOMXPath($this->dom);
        /** @var \DOMElement[] $images */
        $images = $xpath->query('//*[contains(@style, "background")]');
        foreach ($images as $image) {
            $style = $image->getAttribute('style');
            if (empty($style)) {
                continue;
            }
            $image->setAttribute('style', $this->prefixBackgroundImages($style));
            $this->addInitializedClass($image);
        }
    }

    private function addInitializedClass(\DOMElement $element)
    {
        $class = $element->getAttribute('class');
        $element->setAttribute('class', $this->addClass($class, self::FILEJET_INITIALIZED_CLASS));
    }

    private function addLoadingLazy(\DOMElement $element)
    {
        if ($element->hasAttribute('loading')) return;
        $element->setAttribute('loading', 'lazy');
    }

    private function addClass($original, $new)
    {
        if ($original === '') {
            return $new;
        }
        $classes = explode(' ', $original);
        $classes[] = $new;
        return implode(' ', array_unique($classes));
    }

    private function prefixBackgroundImages($style)
    {
        $style = stripslashes($style);
        $rules = explode(';', $style);
        foreach ($rules as $rule) {
            if (strpos($rule, 'background') === false) {
                continue;
            }
            if (strpos($rule, 'url') === false) {
                continue;
            }
            preg_match('~\.*url\([\'"]?([^\'"]*)[\'"]?\)~i', $rule, $matches);
            if (empty($matches)) {
                continue;
            }
            $source = $matches[1];
            if ($source === null) {
                continue;
            }
            $prefixed = $this->prefixImageSource($source);
            $mutated = $this->mutateImage($prefixed);
            $style = str_replace($source, $mutated, $style);
        }
        return $style;
    }
}
