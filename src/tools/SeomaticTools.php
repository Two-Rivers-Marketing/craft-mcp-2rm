<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use craft\elements\Entry;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\contracts\ConditionalToolProvider;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;

/**
 * SEOmatic tools for Craft CMS.
 *
 * Only registered if the SEOmatic plugin (nystudio107/craft-seomatic) is
 * installed. Every SEOmatic reference is a string FQCN resolved lazily and
 * every read/write is duck-typed, so this class loads and its isAvailable()
 * check runs safely when SEOmatic is absent.
 *
 * These tools exist because SEOmatic's per-entry metadata is a full
 * MetaBundle — metaGlobalVars, metaSiteVars, metaSitemapVars, metaContainers,
 * metaBundleSettings — that runs to hundreds of mostly-empty lines when
 * serialized whole. get_seo returns a flat ~16-key projection of the parts
 * anyone actually edits; update_seo writes the same set back.
 *
 * Values are returned raw as SEOmatic stores them, which means a value can be
 * a Twig expression (e.g. `{{ seomatic.helper.extractTextFromField(...) }}`)
 * when the field pulls from another field rather than holding a literal. The
 * `sources` map says which fields are derived and from where, so a raw Twig
 * value is always explained rather than mysterious.
 *
 * @author 2RM
 */
class SeomaticTools implements ConditionalToolProvider {
    /** SEOmatic's plugin class. Referenced as a string so PHP never eagerly resolves it. */
    private const PLUGIN_CLASS = 'nystudio107\seomatic\Seomatic';

    /** SEOmatic's per-element SEO field type. A per-entry override only exists if the entry has one. */
    private const FIELD_CLASS = 'nystudio107\seomatic\fields\SeoSettings';

    /**
     * The flat SEO surface, mapped onto SEOmatic's internals.
     *
     * Each entry is [metaGlobalVars property, metaBundleSettings source key,
     * metaBundleSettings pull-field key, source token meaning "this literal
     * value is authoritative"].
     *
     * canonicalUrl and robots have no source key: they are not pull fields
     * (they are absent from PullField::PULL_TEXT_FIELDS), so their stored
     * value is always used verbatim. Image fields use 'fromUrl' rather than
     * 'fromCustom' because PullField::parseImageSources blanks the stored
     * value for every image source except 'fromUrl'.
     *
     * @var array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string}>
     */
    private const SEO_FIELDS = [
        'title' => ['seoTitle', 'seoTitleSource', 'seoTitleField', 'fromCustom'],
        'description' => ['seoDescription', 'seoDescriptionSource', 'seoDescriptionField', 'fromCustom'],
        'keywords' => ['seoKeywords', 'seoKeywordsSource', 'seoKeywordsField', 'fromCustom'],
        'canonicalUrl' => ['canonicalUrl', null, null, null],
        'robots' => ['robots', null, null, null],
        'ogTitle' => ['ogTitle', 'ogTitleSource', 'ogTitleField', 'fromCustom'],
        'ogDescription' => ['ogDescription', 'ogDescriptionSource', 'ogDescriptionField', 'fromCustom'],
        'ogImage' => ['ogImage', 'ogImageSource', 'ogImageField', 'fromUrl'],
        'twitterTitle' => ['twitterTitle', 'twitterTitleSource', 'twitterTitleField', 'fromCustom'],
        'twitterDescription' => ['twitterDescription', 'twitterDescriptionSource', 'twitterDescriptionField', 'fromCustom'],
        'twitterImage' => ['twitterImage', 'twitterImageSource', 'twitterImageField', 'fromUrl'],
    ];

    /** Source tokens that make SEOmatic derive the value from another content field. */
    private const PULL_FROM_FIELD_SOURCES = [
        'fromField',
        'fromUserField',
        'summaryFromField',
        'keywordsFromField',
    ];

    /**
     * Check if the SEOmatic plugin is available.
     *
     * Uses cached plugin state first (fast), falls back to project config
     * to detect plugins installed after MCP server start.
     */
    public static function isAvailable(): bool {
        if (!class_exists(self::PLUGIN_CLASS)) {
            return false;
        }

        $plugins = Craft::$app->getPlugins();

        if ($plugins->isPluginEnabled('seomatic')) {
            return true;
        }

        $config = Craft::$app->getProjectConfig()->get('plugins.seomatic');
        $enabledInConfig = $config !== null && ($config['enabled'] ?? false) === true;

        if (!$enabledInConfig) {
            return false;
        }

        $plugins->loadPlugins();

        return $plugins->isPluginEnabled('seomatic');
    }

    /**
     * Read an entry's SEO metadata as a compact, flat shape.
     */
    #[McpTool(
        name: 'get_seo',
        description: 'Read an entry\'s SEOmatic metadata as a flat, compact shape: SEO title, description, keywords, canonical URL, robots, and the OG/Twitter title, description, and image. Reports `level` (entry, section, or global) for where the metadata was read from, and a `sources` map naming every field whose value is inherited or derived (global, sameAsSeo, site, asset, field:<handle>) rather than set on the entry. Values are returned exactly as stored, so a derived field can be a Twig expression. Does not return the raw MetaBundle.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getSeo(int $entryId, ?RequestContext $context = null): array {
        return SafeExecution::run(function () use ($entryId): array {
            $entry = self::findEntry($entryId);
            $fieldHandle = self::seoFieldHandle($entry);
            [$bundle, $level] = self::resolveBundle($entry, $fieldHandle);

            $globals = self::prop($bundle, 'metaGlobalVars');

            if (!is_object($globals)) {
                throw new ToolCallException(
                    'SEOmatic returned a meta bundle without metaGlobalVars; the plugin API shape is not one this tool understands.',
                );
            }

            $settings = self::prop($bundle, 'metaBundleSettings');

            $seo = [
                'entryId' => $entryId,
                'entryTitle' => $entry->title,
                'siteId' => (int) $entry->siteId,
                'level' => $level,
                'fieldHandle' => $fieldHandle,
            ];

            $sources = [];

            foreach (self::SEO_FIELDS as $key => [$var, $sourceKey, $pullFieldKey, $_custom]) {
                $value = self::str(self::prop($globals, $var));
                $seo[$key] = $value;

                if ($level !== 'entry' || !is_object($settings)) {
                    continue;
                }

                $origin = self::classifyOrigin(
                    $sourceKey === null ? null : self::str(self::prop($settings, $sourceKey)),
                    $pullFieldKey === null ? null : self::str(self::prop($settings, $pullFieldKey)),
                    $value,
                );

                if ($origin === null) {
                    continue;
                }

                $sources[$key] = $origin;
            }

            $seo['sources'] = $sources;

            return Response::found('seo', $seo);
        });
    }

    /**
     * Write SEO metadata onto an entry.
     */
    #[McpTool(
        name: 'update_seo',
        description: 'Write SEOmatic metadata on an entry. Only non-null parameters are changed; everything else on the entry\'s meta bundle is left untouched. Each written field also has its SEOmatic source switched to the literal-value mode (fromCustom for text, fromUrl for images) so the value is used verbatim instead of being recomputed from another field. Requires the entry to have a SEOmatic SeoSettings field; per-entry overrides are stored in that field. Saves the entry, which invalidates SEOmatic\'s caches.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function updateSeo(
        int $entryId,
        ?string $title = null,
        ?string $description = null,
        ?string $keywords = null,
        ?string $canonicalUrl = null,
        ?string $robots = null,
        ?string $ogTitle = null,
        ?string $ogDescription = null,
        ?string $ogImage = null,
        ?string $twitterTitle = null,
        ?string $twitterDescription = null,
        ?string $twitterImage = null,
        ?RequestContext $context = null,
    ): array {
        $values = [
            'title' => $title,
            'description' => $description,
            'keywords' => $keywords,
            'canonicalUrl' => $canonicalUrl,
            'robots' => $robots,
            'ogTitle' => $ogTitle,
            'ogDescription' => $ogDescription,
            'ogImage' => $ogImage,
            'twitterTitle' => $twitterTitle,
            'twitterDescription' => $twitterDescription,
            'twitterImage' => $twitterImage,
        ];

        return SafeExecution::run(function () use ($entryId, $values): array {
            $entry = self::findEntry($entryId);
            $fieldHandle = self::seoFieldHandle($entry);

            if ($fieldHandle === null) {
                throw new ToolCallException(
                    "Entry {$entryId} has no SEOmatic SeoSettings field in its field layout, so it cannot hold a per-entry SEO override. Add one to the entry type, or edit the section/global SEO defaults in SEOmatic's control panel.",
                );
            }

            $bundle = $entry->getFieldValue($fieldHandle);
            $globals = is_object($bundle) ? self::prop($bundle, 'metaGlobalVars') : null;
            $settings = is_object($bundle) ? self::prop($bundle, 'metaBundleSettings') : null;

            if (!is_object($globals) || !is_object($settings)) {
                throw new ToolCallException(
                    "The '{$fieldHandle}' field on entry {$entryId} did not yield a SEOmatic meta bundle with metaGlobalVars and metaBundleSettings; refusing to write a shape this tool cannot verify.",
                );
            }

            $updated = self::applyValues($globals, $settings, $values);

            if ($updated === []) {
                throw new ToolCallException('No SEO fields given; pass at least one field to update.');
            }

            $entry->setFieldValue($fieldHandle, $bundle);

            if (!Craft::$app->getElements()->saveElement($entry)) {
                throw new ToolCallException('Failed to save entry: ' . json_encode($entry->getErrors()));
            }

            return Response::success([
                'entryId' => $entryId,
                'fieldHandle' => $fieldHandle,
                'updated' => $updated,
            ]);
        });
    }

    /**
     * Assign the non-null values onto the bundle's models, returning the keys written.
     *
     * @param array<string, ?string> $values
     * @return list<string>
     */
    private static function applyValues(object $globals, object $settings, array $values): array {
        $updated = [];

        foreach (self::SEO_FIELDS as $key => [$var, $sourceKey, $_pullFieldKey, $custom]) {
            $value = $values[$key] ?? null;

            if ($value === null) {
                continue;
            }

            $globals->{$var} = $value;
            $updated[] = $key;

            if ($sourceKey === null || $custom === null) {
                continue;
            }

            // Without this, PullField::parseTextSources / parseImageSources
            // would overwrite the literal we just wrote with a template
            // derived from the field's existing source setting.
            $settings->{$sourceKey} = $custom;
        }

        return $updated;
    }

    /**
     * Resolve the most specific meta bundle for the entry.
     *
     * A per-entry override lives in the entry's SeoSettings field value. With
     * no such field (or no value), SEOmatic falls back to the section's
     * content meta bundle, then to the site's global bundle — so this reports
     * which level the returned metadata actually came from.
     *
     * @return array{0: object, 1: string}
     */
    private static function resolveBundle(Entry $entry, ?string $fieldHandle): array {
        $bundle = $fieldHandle === null ? null : $entry->getFieldValue($fieldHandle);

        if (is_object($bundle)) {
            return [$bundle, 'entry'];
        }

        $metaBundles = self::metaBundles();
        $bundle = $metaBundles->getContentMetaBundleForElement($entry);

        if (is_object($bundle)) {
            return [$bundle, 'section'];
        }

        $bundle = $metaBundles->getGlobalMetaBundle((int) $entry->siteId);

        if (is_object($bundle)) {
            return [$bundle, 'global'];
        }

        throw new ToolCallException(
            "SEOmatic has no meta bundle for entry {$entry->id} at the entry, section, or global level.",
        );
    }

    /**
     * Classify where a field's effective value comes from.
     *
     * Returns null when the value is authoritative on the entry itself, and
     * the inheritance/derivation token otherwise. Source tokens are
     * MetaBundleSettings' validated enum values.
     */
    private static function classifyOrigin(?string $source, ?string $pullField, ?string $value): ?string {
        if ($source === 'sameAsGlobal') {
            return 'global';
        }

        if ($source === 'sameAsSeo') {
            return 'sameAsSeo';
        }

        if ($source === 'sameAsSite' || $source === 'sameAsSiteTwitter') {
            return 'site';
        }

        if ($source === 'fromAsset') {
            return 'asset';
        }

        if (in_array($source, self::PULL_FROM_FIELD_SOURCES, true)) {
            return 'field:' . ($pullField === null || $pullField === '' ? '?' : $pullField);
        }

        // A literal source with nothing in it contributes nothing when
        // SEOmatic merges the bundles, so the global value wins.
        if ($value === null || $value === '') {
            return 'global';
        }

        return null;
    }

    private static function findEntry(int $entryId): Entry {
        $entry = Entry::find()->id($entryId)->status(null)->one();

        if (!$entry instanceof Entry) {
            throw new ToolCallException("Entry with ID {$entryId} not found");
        }

        return $entry;
    }

    /**
     * Find the handle of the entry's SEOmatic SeoSettings field, if it has one.
     */
    private static function seoFieldHandle(Entry $entry): ?string {
        $layout = $entry->getFieldLayout();

        if ($layout === null) {
            return null;
        }

        foreach ($layout->getCustomFields() as $field) {
            // Read the handle while $field is still typed as FieldInterface —
            // is_a() against a string FQCN narrows it to a class phpstan
            // cannot see, and the property access would be unresolvable.
            $handle = $field->handle;

            if (!is_a($field, self::FIELD_CLASS)) {
                continue;
            }

            return $handle;
        }

        return null;
    }

    /**
     * SEOmatic's MetaBundles service, reached through the plugin instance so
     * no SEOmatic class is named in resolvable position.
     */
    private static function metaBundles(): object {
        $plugin = Craft::$app->getPlugins()->getPlugin('seomatic');

        if ($plugin === null || !method_exists($plugin, 'getMetaBundles')) {
            throw new ToolCallException(
                'SEOmatic is installed but does not expose a MetaBundles service; this SEOmatic version has an API shape these tools do not support.',
            );
        }

        return $plugin->getMetaBundles();
    }

    /**
     * Read a property off a duck-typed SEOmatic model.
     */
    private static function prop(mixed $object, string $name): mixed {
        if (!is_object($object)) {
            return null;
        }

        if (property_exists($object, $name)) {
            return $object->{$name} ?? null;
        }

        $getter = 'get' . ucfirst($name);

        if (method_exists($object, $getter)) {
            return $object->{$getter}();
        }

        return null;
    }

    /**
     * Normalize a duck-typed read to a string, since SEOmatic's model
     * properties are untyped and hold '' as often as null.
     */
    private static function str(mixed $value): ?string {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        return (string) $value;
    }
}
