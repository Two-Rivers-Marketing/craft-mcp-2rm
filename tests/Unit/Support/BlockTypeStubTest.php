<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\support\BlockTypeStub;

describe('BlockTypeStub default stub', function () {
    it('renders the 2RM body-block skeleton', function () {
        $stub = BlockTypeStub::render('heroBanner', []);

        expect($stub)->toContain('{% extends "global/_includes/body-block-open" %}')
            ->and($stub)->toContain('{% block content %}')
            ->and($stub)->toContain('<div class="main-content {{ containerClass }}">')
            ->and($stub)->toContain('{% endblock %}');
    });

    it('names the block type in the header comment', function () {
        $stub = BlockTypeStub::render('heroBanner', []);

        expect($stub)->toContain('body_blocks/heroBanner.twig');
    });

    it('ends with a trailing newline', function () {
        expect(BlockTypeStub::render('heroBanner', []))->toEndWith("\n");
    });

    it('renders a commented hint per attached field, in order', function () {
        $stub = BlockTypeStub::render('heroBanner', ['heading', 'subHeading', 'ctaLink']);

        expect($stub)->toContain('{# block.heading #}')
            ->and($stub)->toContain('{# block.subHeading #}')
            ->and($stub)->toContain('{# block.ctaLink #}')
            ->and(strpos($stub, 'block.heading'))->toBeLessThan(strpos($stub, 'block.subHeading'))
            ->and(strpos($stub, 'block.subHeading'))->toBeLessThan(strpos($stub, 'block.ctaLink'));
    });

    it('renders no field hints when there are no fields', function () {
        expect(BlockTypeStub::render('divider', []))->not->toContain('{# block.');
    });

    it('omits the children loop when there are no child block types', function () {
        $stub = BlockTypeStub::render('heroBanner', ['heading']);

        expect($stub)->not->toContain('block.children')
            ->and($stub)->not->toContain('{% for item in items %}');
    });
});

describe('BlockTypeStub children loop variant', function () {
    it('renders a block.children loop delegating to the columnItem include', function () {
        $stub = BlockTypeStub::render('featureGrid', ['heading'], ['columnItem', 'callout']);

        expect($stub)->toContain('{% set items = block.children.all() %}')
            ->and($stub)->toContain('{% for item in items %}')
            ->and($stub)->toContain("{% include [globalPaths[0] ~ 'columnItem', globalPaths[1] ~ 'columnItem'] with {")
            ->and($stub)->toContain('parentBlock: block,')
            ->and($stub)->toContain('columnItemPaths: columnItemPaths')
            ->and($stub)->toContain('{% endfor %}');
    });

    it('lists the allowed child block types in a comment', function () {
        $stub = BlockTypeStub::render('featureGrid', [], ['columnItem', 'callout']);

        expect($stub)->toContain('{# child block types: columnItem, callout #}');
    });

    it('keeps field hints alongside the children loop', function () {
        $stub = BlockTypeStub::render('featureGrid', ['heading'], ['columnItem']);

        expect($stub)->toContain('{# block.heading #}')
            ->and($stub)->toContain('block.children.all()')
            ->and(strpos($stub, 'block.heading'))->toBeLessThan(strpos($stub, 'block.children'));
    });
});

describe('BlockTypeStub override template', function () {
    it('substitutes the handle, field hints and children loop tokens', function () {
        $template = "start __BLOCK_HANDLE__\n__FIELD_HINTS__\n__CHILDREN_LOOP__\nend";
        $stub = BlockTypeStub::render('heroBanner', ['heading'], ['columnItem'], $template);

        expect($stub)->toStartWith('start heroBanner')
            ->and($stub)->toContain('{# block.heading #}')
            ->and($stub)->toContain('block.children.all()')
            ->and($stub)->toEndWith('end');
    });

    it('replaces the children loop token with an empty string when there are no children', function () {
        $stub = BlockTypeStub::render('heroBanner', [], [], 'a[__CHILDREN_LOOP__]b');

        expect($stub)->toBe('a[]b');
    });

    it('returns a template without tokens verbatim', function () {
        $template = "{% extends 'custom/base' %}\n{% block content %}{% endblock %}";

        expect(BlockTypeStub::render('heroBanner', ['heading'], [], $template))->toBe($template);
    });

    it('does not fall back to the default skeleton when a template is given', function () {
        $stub = BlockTypeStub::render('heroBanner', [], [], 'custom __BLOCK_HANDLE__');

        expect($stub)->toBe('custom heroBanner')
            ->and($stub)->not->toContain('body-block-open');
    });
});
