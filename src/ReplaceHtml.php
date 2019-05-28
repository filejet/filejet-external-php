<?php

declare(strict_types=1);

namespace FileJet\External;

class ReplaceHtml
{
    private const SOURCE_PLACEHOLDER = "#source#";
    private const FILEJET_IMAGE_CLASS = 'fj-image';

    /** @var string */
    private $urlPrefix;
    /** @var \DOMDocument */
    private $dom;
    /** @var string|null */
    private $basePath;
    /** @var string */
    private $lazyLoadAttribute;

    public function __construct(string $storageId, string $lazyLoadAttribute, ?string $basePath = null)
    {
        $source = self::SOURCE_PLACEHOLDER;
        $this->urlPrefix = "https://{$storageId}.5gcdn.net/ext/auto?src={$source}";
        $this->basePath = $basePath;

        $this->dom = new \DOMDocument();
        $this->lazyLoadAttribute = $lazyLoadAttribute;
    }

    public function replaceImages(?string $content = null): string
    {
        if (empty($content)) return '';

        libxml_use_internal_errors(true);
        $this->dom->loadHTML(
            mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $this->replaceImageTags();
        $this->replaceStyleBackground();

        return $this->dom->saveHTML();
    }

    private function replaceImageTags()
    {
        /** @var \DOMElement[] $images */
        $images = $this->dom->getElementsByTagName('img');
        foreach ($images as $image) {
            $sourceAttribute = $image->hasAttribute('src') ? 'src' : $this->lazyLoadAttribute;
            if ($image->parentNode->tagName === 'noscript') continue;

            $originalSource = $image->getAttribute($sourceAttribute);
            if($this->isDataURL($originalSource)) continue;
            if (strpos($originalSource, '.svg') !== false) continue;

            $image->parentNode->appendChild($this->createNoScript($image));

            $image->removeAttribute('src');
            $image->removeAttribute($sourceAttribute);
            $image->setAttribute('data-filejet-src', $this->prefixImageSource($originalSource));

            $this->replaceClass($image);
        }
    }

    private function replaceStyleBackground()
    {
        $xpath = new \DOMXPath($this->dom);

        /** @var \DOMElement[] $images */
        $images = $xpath->query('//*[contains(@style, "background")]');

        foreach ($images as $image) {
            $style = $image->getAttribute('style');

            if (empty($style)) continue;

            $image->setAttribute('style', $this->prefixBackgroundImages($style));
            $this->replaceClass($image);
        };

    }

    public function prefixImageSource(string $originalSource): string
    {
        $source = strpos($originalSource, $this->basePath) === 0
            ? $originalSource
            : "{$this->basePath}{$originalSource}";

        return str_replace(self::SOURCE_PLACEHOLDER, urlencode($source), $this->urlPrefix);
    }

    private function prefixBackgroundImages(string $style): string
    {
        $style = stripslashes($style);

        $rules = explode(';', $style);
        foreach ($rules as $rule) {
            if (strpos($rule, 'background') === false) continue;
            if (strpos($rule, 'url') === false) continue;

            preg_match('~\.*url\([\'"]?([^\'"]*)[\'"]?\)~i', $rule, $matches);
            if (empty($matches)) continue;

            $source = $matches[1];
            if ($source === null) continue;

            $prefixed = $this->prefixImageSource($source);
            $style = str_replace($source, $prefixed, $style);
        }

        return $style;
    }

    private function createNoScript(\DOMNode $originalImage): \DOMNode
    {
        $noScript = $this->dom->createElement('noscript');
        $noScript->appendChild($originalImage->cloneNode());

        return $noScript;
    }

    private function replaceClass(\DOMElement $element)
    {
        $class = $element->getAttribute('class');
        $element->setAttribute('class', $this->addClass($class, self::FILEJET_IMAGE_CLASS));
    }

    private function addClass(string $original, string $new): string
    {
        if ($original === '') return $new;

        $classes = explode(' ', $original);
        array_push($classes, $new);

        return implode(' ', $classes);
    }

    private function isDataURL(string $source): bool
    {
        return (bool) preg_match("/^\s*data:([a-z]+\/[a-z]+(;[a-z\-]+\=[a-z\-]+)?)?(;base64)?,[a-z0-9\!\$\&\'\,\(\)\*\+\,\;\=\-\.\_\~\:\@\/\?\%\s]*\s*$/i", $source);
    }

}
