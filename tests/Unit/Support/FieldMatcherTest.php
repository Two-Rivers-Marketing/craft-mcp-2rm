<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\support\FieldMatcher;

describe('FieldMatcher::matchExisting', function () {
    $existing = [
        ['handle' => 'heading', 'name' => 'Heading', 'class' => 'craft\fields\PlainText'],
        ['handle' => 'subHeading', 'name' => 'Sub Heading', 'class' => 'craft\fields\PlainText'],
        ['handle' => 'showTitle', 'name' => 'Show Title', 'class' => 'craft\fields\Lightswitch'],
    ];

    it('matches on exact handle regardless of type', function () use ($existing) {
        $match = FieldMatcher::matchExisting('heading', 'Totally Different', 'craft\fields\Dropdown', $existing);

        expect($match)->not->toBeNull()
            ->and($match['handle'])->toBe('heading')
            ->and($match['reason'])->toBe('handle');
    });

    it('matches on same type and similar name', function () use ($existing) {
        $match = FieldMatcher::matchExisting('subheadline', 'sub-heading', 'craft\fields\PlainText', $existing);

        expect($match)->not->toBeNull()
            ->and($match['handle'])->toBe('subHeading')
            ->and($match['reason'])->toBe('type+name');
    });

    it('ignores name similarity across different field types', function () use ($existing) {
        $match = FieldMatcher::matchExisting('showTitleToggle', 'Show Title', 'craft\fields\PlainText', $existing);

        expect($match)->toBeNull();
    });

    it('prefers a handle match over a type+name match', function () use ($existing) {
        $match = FieldMatcher::matchExisting('showTitle', 'Sub Heading', 'craft\fields\PlainText', $existing);

        expect($match['handle'])->toBe('showTitle')
            ->and($match['reason'])->toBe('handle');
    });

    it('returns null when nothing matches', function () use ($existing) {
        expect(FieldMatcher::matchExisting('ctaLink', 'CTA Link', 'craft\fields\Url', $existing))->toBeNull();
    });

    it('returns null for a null or empty name with no handle match', function () use ($existing) {
        expect(FieldMatcher::matchExisting('brandNew', null, 'craft\fields\PlainText', $existing))->toBeNull()
            ->and(FieldMatcher::matchExisting('brandNew', '  ', 'craft\fields\PlainText', $existing))->toBeNull();
    });

    it('returns null against an empty field list', function () {
        expect(FieldMatcher::matchExisting('heading', 'Heading', 'craft\fields\PlainText', []))->toBeNull();
    });

    it('compares names ignoring case and punctuation', function () {
        $existing = [['handle' => 'bodyText', 'name' => 'Body — Text!', 'class' => 'craft\fields\PlainText']];
        $match = FieldMatcher::matchExisting('body', 'body text', 'craft\fields\PlainText', $existing);

        expect($match['handle'])->toBe('bodyText')
            ->and($match['reason'])->toBe('type+name');
    });
});

describe('FieldMatcher::closeCandidates', function () {
    it('suggests handles within a small edit distance', function () {
        $candidates = FieldMatcher::closeCandidates('headng', ['heading', 'body', 'footerNav']);

        expect($candidates)->toBe(['heading']);
    });

    it('suggests handles containing the missing handle', function () {
        $candidates = FieldMatcher::closeCandidates('heading', ['subHeading', 'body']);

        expect($candidates)->toBe(['subHeading']);
    });

    it('orders candidates closest first', function () {
        $candidates = FieldMatcher::closeCandidates('heading', ['headings', 'heading2x', 'body']);

        expect($candidates[0])->toBe('headings');
    });

    it('excludes unrelated handles', function () {
        expect(FieldMatcher::closeCandidates('heading', ['calloutLayout', 'productSku']))->toBe([]);
    });

    it('respects the limit', function () {
        $handles = ['heading1', 'heading2', 'heading3', 'heading4', 'heading5', 'heading6'];

        expect(FieldMatcher::closeCandidates('heading', $handles, 3))->toHaveCount(3);
    });

    it('is case-insensitive', function () {
        expect(FieldMatcher::closeCandidates('HEADING', ['heading']))->toBe(['heading']);
    });
});
