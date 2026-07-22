<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\support\FreeformFormDeletionCascade;

describe('FreeformFormDeletionCascade::buildSpecs', function () {
    it('emits the form-keyed tables in FK-safe order when there are no submissions', function () {
        $specs = FreeformFormDeletionCascade::buildSpecs(9, []);

        expect($specs)->toHaveCount(5);

        $labels = array_column($specs, 'label');
        expect($labels)->toBe([
            'freeform_forms_fields',
            'freeform_forms_rows',
            'freeform_forms_pages',
            'freeform_forms_layouts',
            'freeform_submissions',
        ]);

        foreach ($specs as $spec) {
            expect($spec['column'])->toBe('formId')
                ->and($spec['values'])->toBe([9]);
        }
    });

    it('appends the element-keyed tables after the form tables when submissions exist', function () {
        $specs = FreeformFormDeletionCascade::buildSpecs(9, [101, 102]);

        expect($specs)->toHaveCount(8);

        $labels = array_column($specs, 'label');
        expect($labels)->toBe([
            'freeform_forms_fields',
            'freeform_forms_rows',
            'freeform_forms_pages',
            'freeform_forms_layouts',
            'freeform_submissions',
            'searchindex',
            'elements_sites',
            'elements',
        ]);
    });

    it('keys the element tables on the submission ids with the right columns', function () {
        $specs = FreeformFormDeletionCascade::buildSpecs(9, [101, 102]);
        $byLabel = array_column($specs, null, 'label');

        expect($byLabel['searchindex']['column'])->toBe('elementId')
            ->and($byLabel['searchindex']['values'])->toBe([101, 102])
            ->and($byLabel['elements_sites']['column'])->toBe('elementId')
            ->and($byLabel['elements']['column'])->toBe('id')
            ->and($byLabel['elements']['values'])->toBe([101, 102]);
    });

    it('deletes elements after the index/site rows that hang off them', function () {
        $labels = array_column(FreeformFormDeletionCascade::buildSpecs(9, [1]), 'label');

        $searchindex = array_search('searchindex', $labels, true);
        $sites = array_search('elements_sites', $labels, true);
        $elements = array_search('elements', $labels, true);

        expect($searchindex)->toBeLessThan($elements)
            ->and($sites)->toBeLessThan($elements);
    });

    it('reindexes non-sequential submission id keys to a plain list', function () {
        $specs = FreeformFormDeletionCascade::buildSpecs(9, [3 => 101, 7 => 102]);
        $byLabel = array_column($specs, null, 'label');

        expect($byLabel['elements']['values'])->toBe([101, 102]);
    });
});

describe('FreeformFormDeletionCascade::tableLabel', function () {
    it('strips the Craft table wrapper', function () {
        expect(FreeformFormDeletionCascade::tableLabel('{{%freeform_forms_fields}}'))
            ->toBe('freeform_forms_fields')
            ->and(FreeformFormDeletionCascade::tableLabel('{{%elements}}'))->toBe('elements');
    });
});

describe('FreeformFormDeletionCascade::allClean', function () {
    it('is true only when every count is zero', function () {
        expect(FreeformFormDeletionCascade::allClean([]))->toBeTrue()
            ->and(FreeformFormDeletionCascade::allClean(['a' => 0, 'b' => 0]))->toBeTrue()
            ->and(FreeformFormDeletionCascade::allClean(['a' => 0, 'b' => 1]))->toBeFalse();
    });
});

describe('FreeformFormDeletionCascade summaries', function () {
    it('shapes the dryRun preview', function () {
        $summary = FreeformFormDeletionCascade::dryRunSummary(
            9,
            'contactForm',
            'freeform_submissions_contact_form_9',
            2,
            ['freeform_forms_fields' => 6, 'elements' => 2],
        );

        expect($summary)->toBe([
            'dryRun' => true,
            'form' => ['id' => 9, 'handle' => 'contactForm'],
            'submissions' => 2,
            'contentTable' => 'freeform_submissions_contact_form_9',
            'wouldDelete' => ['freeform_forms_fields' => 6, 'elements' => 2],
        ]);
    });

    it('shapes the post-delete payload and reports orphansClean from the remaining counts', function () {
        $clean = FreeformFormDeletionCascade::deletedSummary(
            9,
            'contactForm',
            'freeform_submissions_contact_form_9',
            2,
            ['freeform_forms_fields' => 6, 'elements' => 2],
            ['freeform_forms_fields' => 0, 'elements' => 0],
        );

        expect($clean['form'])->toBe(['id' => 9, 'handle' => 'contactForm'])
            ->and($clean['submissionsDeleted'])->toBe(2)
            ->and($clean['contentTableDropped'])->toBe('freeform_submissions_contact_form_9')
            ->and($clean['cleaned'])->toBe(['freeform_forms_fields' => 6, 'elements' => 2])
            ->and($clean['orphansRemaining'])->toBe(['freeform_forms_fields' => 0, 'elements' => 0])
            ->and($clean['orphansClean'])->toBeTrue();

        $dirty = FreeformFormDeletionCascade::deletedSummary(
            9,
            'contactForm',
            'freeform_submissions_contact_form_9',
            2,
            ['freeform_forms_fields' => 6],
            ['freeform_forms_fields' => 3],
        );

        expect($dirty['orphansClean'])->toBeFalse();
    });
});
