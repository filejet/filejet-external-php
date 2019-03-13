<?php

declare(strict_types=1);

namespace FileJet\External;

class ReplaceHtml
{
    private const SOURCE_PLACEHOLDER = "#source#";

    /** @var string */
    private $urlPrefix;
    /** @var \DOMDocument */
    private $dom;
    /** @var string */
    private $fileJetImageClass;

    public function __construct(string $storageId, string $defaultMutation, string $fileJetImageClass)
    {
        $source = self::SOURCE_PLACEHOLDER;
        $this->urlPrefix = "https://{$storageId}.5gcdn.net/ext/{$defaultMutation}?src={$source}";
        $this->fileJetImageClass = $fileJetImageClass;

        $this->dom = new \DOMDocument();
    }

    public function replaceImages(?string $content = null): string
    {
        if (empty($content)) return '';

        libxml_use_internal_errors(true);
        $this->dom->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $this->replaceImageTags();
        $this->replaceStyleBackground();

        return $this->dom->saveHTML();
    }

    private function replaceStyleBackground()
    {
        $xpath = new \DOMXPath($this->dom);

        /** @var \DOMElement[] $images */
        $images = $xpath->query('//*[contains(@style, "background")]');

        foreach ($images as $image) {
            if ($image->hasAttribute('data-src')) continue;

            $style = $image->getAttribute('style');
            if (empty($style)) continue;

            preg_match_all(
                '~\bbackground(-image)?\s*:(.*?)\(\s*(\'|")?(?<image>.*?)\3?\s*\)~i',
                $style,
                $matches
            );

            $replaced = [];
            foreach ($matches['image'] as $background) {
                $prefixed = $this->prefixImageSource($background);
                $style = str_replace($background, $prefixed, $style);

                $replaced[] = $prefixed;
            }

            $image->setAttribute('style', $style);
            $image->setAttribute('data-src', implode(', ', $replaced));

            $class = $image->getAttribute('class');
            $image->setAttribute('class', $this->addClass($class, $this->fileJetImageClass));
        };

    }

    private function prefixImageSource(string $originalSource)
    {
        return str_replace(self::SOURCE_PLACEHOLDER, urlencode($originalSource), $this->urlPrefix);
    }

    private function replaceImageTags()
    {
        /** @var \DOMElement[] $images */
        $images = $this->dom->getElementsByTagName('img');
        foreach ($images as $image) {
            if ($image->hasAttribute('data-src')) continue;
            if ($image->parentNode->tagName === 'noscript') continue;

            $originalSource = $image->getAttribute('src');
            $image->parentNode->appendChild($this->createNoScript($image));

            $image->removeAttribute('src');
            $image->setAttribute('src', $this->prefixImageSource($originalSource));

            $image->removeAttribute('srcset');
            $image->removeAttribute('sizes');

            $class = $image->getAttribute('class');

            $image->setAttribute('class', $this->addClass($class, $this->fileJetImageClass));
        }
    }

    private function createNoScript(\DOMNode $originalImage): \DOMNode
    {
        $noScript = $this->dom->createElement('noscript');
        $noScript->appendChild($originalImage->cloneNode());

        return $noScript;
    }

    private function addClass(string $original, string $new): string
    {
        if ($original === '') return $new;

        $classes = explode(' ', $original);
        array_push($classes, $new);

        return implode(' ', $classes);
    }
}