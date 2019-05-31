<?php

declare(strict_types=1);

namespace FileJet\External;

class ReplaceHtml
{
    const SOURCE_PLACEHOLDER = "#source#";
    const MUTATION_PLACEHOLDER = "#mutation#";
    const FILEJET_IGNORE_CLASS = 'fj-ignore';
    const FILEJET_FILL_CLASS = 'fj-fill';

    const ATTRIBUTE_SRC = 'src';
    const ATTRIBUTE_SRCSET = 'srcset';
    const ATTRIBUTE_LAZY_SRC = 'data-lazy-src';
    const ATTRIBUTE_LAZY_SRCSET = 'data-lazy-srcset';

    /** @var string */
    private $urlPrefix;
    /** @var \DOMDocument */
    private $dom;
    /** @var string|null */
    private $basePath;
    /** @var string */
    private $secret;

    public function __construct(
        string $storageId,
        string $lazyLoadAttribute = null,
        string $basePath = null,
        string $secret = null
    )
    {
        $source = self::SOURCE_PLACEHOLDER;
        $mutation = self::MUTATION_PLACEHOLDER;
        $this->urlPrefix = "https://{$storageId}.5gcdn.net/ext/{$mutation}?src={$source}";
        $this->basePath = $basePath;
        $this->secret = $secret;
        $this->dom = new \DOMDocument();
    }

    public function replaceImages(string $content = null, array $ignored = [], array $mutations = []): string
    {
        if (empty($content)) return '';

        libxml_use_internal_errors(true);
        $this->dom->loadHTML(
            mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $this->replaceImageTags($ignored, $mutations);
        return $this->dom->saveHTML();
    }

    public function prefixImageSource(string $originalSource): string
    {
        $source = strpos($originalSource, $this->basePath) === 0
            ? $originalSource
            : "{$this->basePath}{$originalSource}";

        return str_replace(self::SOURCE_PLACEHOLDER, urlencode($source), $this->urlPrefix) . $this->signUrl($originalSource);
    }

    private function replaceImageTags(array $ignored = [], array $mutations = [])
    {
        /** @var \DOMElement[] $images */
        $images = $this->dom->getElementsByTagName('img');
        $ignored = array_merge($ignored, [self::FILEJET_IGNORE_CLASS => self::FILEJET_IGNORE_CLASS]);

        foreach ($images as $image) {
            if (false === empty(array_intersect(explode(' ', $image->getAttribute('class') ?? ''), $ignored))) {
                continue;
            }

            if (false === empty(array_intersect(explode(' ', $image->parentNode->getAttribute('class') ?? ''), $ignored))) {
                continue;
            }

            $this->handleSource($image);
            $this->handleLazySource($image);
        }
    }

    private function handleLazySource($image)
    {
        $this->handleSource($image, self::ATTRIBUTE_LAZY_SRC, self::ATTRIBUTE_LAZY_SRCSET);
    }

    private function handleSource($image, $originalAttribute = self::ATTRIBUTE_SRC, $setAttribute = self::ATTRIBUTE_SRCSET)
    {
        $originalSource = $image->getAttribute($originalAttribute);
        if ($this->isDataURL($originalSource)) return;
        if (strpos($originalSource, '.svg') !== false) return;

        $this->toAbsoluteUri($originalSource);

        $fill = false;
        if (strpos($image->getAttribute('class'), self::FILEJET_FILL_CLASS) !== false || strpos($image->parentNode->getAttribute('class'), self::FILEJET_FILL_CLASS) !== false) $fill = true;

        $height = $this->getHeight($image);
        $width = $this->getWidth($image);
        $ratio = $width !== null && $height !== null ? $this->getAspectRatio((int)$width, (int)$height) : null;


        $customMutations = false === empty($imageClasses) ? array_intersect_key($mutations, array_flip($imageClasses)) : [];
        $prefixedSource = $this->prefixImageSource($originalSource);
        $image->setAttribute($originalAttribute, $this->mutateImage($prefixedSource, $height, $width, $fill, $customMutations));

        $srcSet = $image->getAttribute($setAttribute);
        if (empty($srcSet)) {
            return;
        }

        $sources = explode(', ', $srcSet);
        $newSources = [];

        foreach ($sources as $source) {
            list($url, $w) = explode(' ', $source);
            $widthAsInt = (int)$w;
            $customMutation = "resize_$widthAsInt";
            if ($ratio !== null) {
                $h = round($widthAsInt / $ratio);
                $customMutation .= "x$h";
            }
            $prefixedSource = $this->prefixImageSource($url);
            $newUrl = $this->mutateImage($prefixedSource, null, null, false, array_merge($customMutations, [$customMutation]));
            $newSources[] = "$newUrl $w";

        }

        $image->setAttribute($setAttribute, implode(', ', $newSources));
    }

    private function toAbsoluteUri(&$uri)
    {
        $parsed = parse_url($uri);
        if (empty($parsed['scheme'])) {
            $path = $parsed['path'] ?? '';
            $host = $_SERVER['HTTP_HOST'];
            if (strpos($uri, $host) !== false) {
                $path = substr(strstr($uri, $host), strlen($host));
            }
            $actual_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://$host";
            $uri = $actual_link . '/' . trim($path, '/');
        }
    }

    public function getAspectRatio(int $width, int $height): float
    {
        return $width > $height ? ($width / $height) : ($height / $width);
    }

    public function mutateImage(string $source, string $height = null, string $width = null, bool $fill = false, array $customMutations = []): string
    {
        $mutation = 'auto';

        if (false === empty($customMutations)) {
            $mutation = implode(',', array_merge($customMutations, ['auto']));
        } else if (!empty($height) && empty($width)) {
            $mutation = "resize_x" . $height . "shrink," . $mutation;
        } else if (empty($height) && !empty($width)) {
            $mutation = "resize_" . $width . "shrink," . $mutation;
        } else if ($fill && !empty($height) && !empty($width)) {
            $mutation = "resize_" . $width . "x" . $height . ",crop_" . $width . "x" . $height . ",pos_center,fill_" . $width . "x" . $height . ",bg_transparent," . $mutation;
        } else if (!empty($height) && !empty($width)) {
            $mutation = "fit_$width" . "x" . "$height," . $mutation;
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

        $style = $image->getAttribute('style');
        $style = stripslashes($style);
        $rules = explode(';', $style);

        foreach ($dimensions as $dimension) {
            $value = null;
            if (!empty($value = $image->getAttribute($dimension))) return $value;

            if (count($rules) == 0) continue;

            foreach ($rules as $rule) {
                if (strpos($rule, $dimension) !== false) {
                    if (strpos($rule, 'em') !== false
                        || strpos($rule, 'ex') !== false
                        || strpos($rule, 'rem') !== false
                        || strpos($rule, 'vw') !== false
                        || strpos($rule, 'vh') !== false
                        || strpos($rule, '%') !== false
                        || strpos($rule, 'ch') !== false
                    ) continue;
                    $dimensionValue = trim(str_replace('px', '', substr($rule, strpos($rule, ":") + 1)));
                    if (is_numeric($dimensionValue)) return $dimensionValue;

                }
            }
        }
        return null;
    }

    private function signUrl($url)
    {
        if ($this->secret == null) return '';
        return '&sig=' . hash_hmac('sha256', $url, $this->secret);
    }

    private function isDataURL(string $source): bool
    {
        return (bool)preg_match("/^\s*data:([a-z]+\/[a-z]+(;[a-z\-]+\=[a-z\-]+)?)?(;base64)?,[a-z0-9\!\$\&\'\,\(\)\*\+\,\;\=\-\.\_\~\:\@\/\?\%\s]*\s*$/i", $source);
    }
}

