<?php

declare(strict_types=1);

use twoRivers\craft\Mcp\support\FreeformSerializer;

function makeFreeformStub(array $props = []): object {
    return new #[AllowDynamicProperties] class ($props) {
        public function __construct(array $props) {
            foreach ($props as $name => $value) {
                $this->$name = $value;
            }
        }
    };
}

describe('FreeformSerializer::readProp()', function () {
    it('reads a declared public property', function () {
        $obj = makeFreeformStub(['handle' => 'contactForm']);

        expect(FreeformSerializer::readProp($obj, 'handle'))->toBe('contactForm');
    });

    it('falls back to a getter method', function () {
        $obj = new class () {
            public function getHandle(): string {
                return 'viaGetter';
            }
        };

        expect(FreeformSerializer::readProp($obj, 'handle'))->toBe('viaGetter');
    });

    it('returns null for missing properties and getters', function () {
        $obj = makeFreeformStub();

        expect(FreeformSerializer::readProp($obj, 'nope'))->toBeNull();
    });

    it('prefers the getter over a NON-public property (Freeform field shape)', function () {
        // Freeform fields declare $handle/$label protected with getHandle()/
        // getLabel() accessors; reading the raw property must not win.
        $obj = new class () {
            protected string $handle = 'RAW-PROTECTED';

            public function getHandle(): string {
                return 'viaGetter';
            }
        };

        expect(FreeformSerializer::readProp($obj, 'handle'))->toBe('viaGetter');
    });

    it('falls back to an is<Name>() accessor (isRequired for "required")', function () {
        $obj = new class () {
            protected bool $required = false;

            public function isRequired(): bool {
                return true;
            }
        };

        expect(FreeformSerializer::readProp($obj, 'required'))->toBeTrue();
    });
});

describe('FreeformSerializer::formSummary()', function () {
    it('reads id, handle and name', function () {
        $form = makeFreeformStub(['id' => 5, 'handle' => 'contactForm', 'name' => 'Contact Form']);

        expect(FreeformSerializer::formSummary($form))->toBe([
            'id' => 5,
            'handle' => 'contactForm',
            'name' => 'Contact Form',
        ]);
    });
});

describe('FreeformSerializer::fieldLayout() and fieldSummary()', function () {
    it('reads fields via getLayout()->getFields()', function () {
        $field = makeFreeformStub(['handle' => 'email', 'label' => 'Email', 'required' => true]);
        $layout = new class ($field) {
            public function __construct(private object $field) {
            }

            public function getFields(): array {
                return [$this->field];
            }
        };
        $form = new class ($layout) {
            public function __construct(private object $layout) {
            }

            public function getLayout(): object {
                return $this->layout;
            }
        };

        expect(FreeformSerializer::fieldLayout($form))->toBe([
            ['handle' => 'email', 'type' => $field::class, 'label' => 'Email', 'required' => true],
        ]);
    });

    it('falls back to getFields() directly on the form when there is no layout', function () {
        $field = makeFreeformStub(['handle' => 'name', 'label' => 'Name', 'required' => false]);
        $form = new class ($field) {
            public function __construct(private object $field) {
            }

            public function getFields(): array {
                return [$this->field];
            }
        };

        $fields = FreeformSerializer::fieldLayout($form);

        expect($fields)->toHaveCount(1)
            ->and($fields[0]['handle'])->toBe('name')
            ->and($fields[0]['required'])->toBeFalse();
    });

    it('returns an empty list when no field accessor is present', function () {
        $form = makeFreeformStub(['id' => 1]);

        expect(FreeformSerializer::fieldLayout($form))->toBe([]);
    });

    it('prefers getType() over the class name for field type', function () {
        $field = new class () {
            public function getType(): string {
                return 'text';
            }
        };

        expect(FreeformSerializer::fieldSummary($field)['type'])->toBe('text');
    });

    it('falls back to the class name when getType() is absent', function () {
        $field = makeFreeformStub(['handle' => 'x']);

        expect(FreeformSerializer::fieldSummary($field)['type'])->toBe($field::class);
    });

    it('serializes a real-shaped Freeform field (non-public props + getters + isRequired)', function () {
        $field = new class () {
            protected string $handle = 'name';

            protected string $label = 'Name';

            protected bool $required = true;

            public function getHandle(): string {
                return $this->handle;
            }

            public function getLabel(): string {
                return $this->label;
            }

            public function isRequired(): bool {
                return $this->required;
            }

            public function getType(): string {
                return 'text';
            }
        };

        expect(FreeformSerializer::fieldSummary($field))->toBe([
            'handle' => 'name',
            'type' => 'text',
            'label' => 'Name',
            'required' => true,
        ]);
    });
});

describe('FreeformSerializer::submissionSummary()', function () {
    it('reads id, formId, title and a scalar status property', function () {
        $submission = makeFreeformStub([
            'id' => 10,
            'formId' => 2,
            'title' => 'Submission #10',
            'status' => 'open',
        ]);

        expect(FreeformSerializer::submissionSummary($submission))->toBe([
            'id' => 10,
            'formId' => 2,
            'title' => 'Submission #10',
            'status' => 'open',
            'dateCreated' => null,
        ]);
    });

    it('reads status handle from a getStatus() object', function () {
        $status = makeFreeformStub(['handle' => 'pending', 'name' => 'Pending']);
        $submission = new class ($status) {
            public int $id = 11;

            public function __construct(private object $status) {
            }

            public function getStatus(): object {
                return $this->status;
            }
        };

        expect(FreeformSerializer::submissionSummary($submission)['status'])->toBe('pending');
    });

    it('formats a DateTimeInterface dateCreated', function () {
        $submission = makeFreeformStub(['dateCreated' => new DateTimeImmutable('2026-01-02 03:04:05')]);

        expect(FreeformSerializer::submissionSummary($submission)['dateCreated'])->toBe('2026-01-02 03:04:05');
    });
});

describe('FreeformSerializer::submissionFieldValues()', function () {
    it('reads values via getFieldValue()', function () {
        $submission = new class () {
            public function getFieldValue(string $handle): mixed {
                return match ($handle) {
                    'email' => 'a@b.com',
                    default => null,
                };
            }
        };

        expect(FreeformSerializer::submissionFieldValues($submission, ['email']))->toBe(['email' => 'a@b.com']);
    });

    it('falls back to direct property access when getFieldValue() is absent', function () {
        $submission = makeFreeformStub(['email' => 'c@d.com']);

        expect(FreeformSerializer::submissionFieldValues($submission, ['email']))->toBe(['email' => 'c@d.com']);
    });

    it('returns null for a handle neither getFieldValue() nor a property can resolve', function () {
        $submission = makeFreeformStub([]);

        expect(FreeformSerializer::submissionFieldValues($submission, ['missing']))->toBe(['missing' => null]);
    });

    it('reads the value from a field object exposed via a magic getter (Freeform 5 shape)', function () {
        // Freeform 5: $submission->{handle} returns the field object; the stored
        // value is field->getValue(). getFieldValue() throws here, so it must
        // not be the path taken.
        $submission = new class () {
            public function __get(string $name): object {
                return new class ($name) {
                    public function __construct(private string $name) {
                    }

                    public function getValue(): string {
                        return "value-of-{$this->name}";
                    }
                };
            }

            public function getFieldValue(string $handle): mixed {
                throw new RuntimeException("Invalid field handle: {$handle}");
            }
        };

        expect(FreeformSerializer::submissionFieldValues($submission, ['name']))
            ->toBe(['name' => 'value-of-name']);
    });
});

describe('FreeformSerializer::toCsv()', function () {
    it('writes a header row and data rows in header order', function () {
        $csv = FreeformSerializer::toCsv(
            [['id' => 1, 'email' => 'a@b.com'], ['id' => 2, 'email' => 'c@d.com']],
            ['id', 'email'],
        );

        expect($csv)->toBe("id,email\n1,a@b.com\n2,c@d.com\n");
    });

    it('fills missing keys with an empty cell', function () {
        $csv = FreeformSerializer::toCsv([['id' => 1]], ['id', 'email']);

        expect($csv)->toBe("id,email\n1,\n");
    });

    it('drops row keys not present in headers', function () {
        $csv = FreeformSerializer::toCsv([['id' => 1, 'secret' => 'x']], ['id']);

        expect($csv)->toBe("id\n1\n");
    });

    it('quotes values containing commas', function () {
        $csv = FreeformSerializer::toCsv([['note' => 'a, b']], ['note']);

        expect($csv)->toBe("note\n\"a, b\"\n");
    });

    it('encodes non-scalar values as JSON', function () {
        $csv = FreeformSerializer::toCsv([['tags' => ['a', 'b']]], ['tags']);

        expect($csv)->toBe("tags\n\"[\"\"a\"\",\"\"b\"\"]\"\n");
    });
});

describe('FreeformSerializer::notification()', function () {
    it('shapes a Freeform notification template record (Yii attributes via magic getter)', function () {
        // NotificationTemplateRecord exposes id/handle/name/... as Yii
        // ActiveRecord attributes, reachable through canGetProperty()/__get.
        $record = new class () {
            /** @var array<string, mixed> */
            private array $attrs = [
                'id' => 7,
                'handle' => 'adminEmail',
                'name' => 'Admin Email',
                'formId' => 3,
                'subject' => 'New submission',
                'fromName' => 'Site',
                'fromEmail' => 'site@example.com',
                'replyToEmail' => 'reply@example.com',
                'cc' => 'cc@example.com',
                'bcc' => null,
            ];

            public function canGetProperty(string $name): bool {
                return array_key_exists($name, $this->attrs);
            }

            public function __get(string $name): mixed {
                return $this->attrs[$name] ?? null;
            }
        };

        expect(FreeformSerializer::notification($record))->toBe([
            'id' => 7,
            'handle' => 'adminEmail',
            'name' => 'Admin Email',
            'formId' => 3,
            'subject' => 'New submission',
            'fromName' => 'Site',
            'fromEmail' => 'site@example.com',
            'replyToEmail' => 'reply@example.com',
            'cc' => 'cc@example.com',
            'bcc' => null,
        ]);
    });
});

describe('FreeformSerializer::integration()', function () {
    it('shapes a Freeform integration (getters + type from getTypeDefinition + isEnabled)', function () {
        $integration = new class () {
            public function getId(): int {
                return 1;
            }

            public function getHandle(): string {
                return 'recaptcha';
            }

            public function getName(): string {
                return 'reCAPTCHA';
            }

            public function isEnabled(): bool {
                return true;
            }

            public function getTypeDefinition(): object {
                return new #[AllowDynamicProperties] class () {
                    public string $type = 'captchas';
                };
            }
        };

        expect(FreeformSerializer::integration($integration))->toBe([
            'id' => 1,
            'handle' => 'recaptcha',
            'name' => 'reCAPTCHA',
            'type' => 'captchas',
            'enabled' => true,
        ]);
    });

    it('degrades type to null when getTypeDefinition() is absent', function () {
        $integration = new class () {
            public function getId(): int {
                return 2;
            }

            public function getHandle(): string {
                return 'x';
            }

            public function getName(): string {
                return 'X';
            }
        };

        $result = FreeformSerializer::integration($integration);

        expect($result['type'])->toBeNull()
            ->and($result['enabled'])->toBeFalse();
    });
});

describe('FreeformSerializer::form()', function () {
    it('maps notification, connection, and spam collections through their item serializers', function () {
        $form = makeFreeformStub(['id' => 3, 'handle' => 'contactForm', 'name' => 'Contact Form']);
        $connection = new class () {
            public function getId(): int {
                return 9;
            }

            public function getHandle(): string {
                return 'entryConnection';
            }

            public function getName(): string {
                return 'Entry';
            }

            public function isEnabled(): bool {
                return true;
            }

            public function getTypeDefinition(): object {
                return new #[AllowDynamicProperties] class () {
                    public string $type = 'elements';
                };
            }
        };

        $result = FreeformSerializer::form($form, [], [$connection], []);

        expect($result['id'])->toBe(3)
            ->and($result['handle'])->toBe('contactForm')
            ->and($result['fields'])->toBe([])
            ->and($result['notifications'])->toBe([])
            ->and($result['spamSettings'])->toBe([])
            ->and($result['connections'])->toBe([[
                'id' => 9,
                'handle' => 'entryConnection',
                'name' => 'Entry',
                'type' => 'elements',
                'enabled' => true,
            ]]);
    });

    it('defaults all three settings sections to empty lists when nothing is passed', function () {
        $form = makeFreeformStub(['id' => 1, 'handle' => 'f', 'name' => 'F']);

        expect(FreeformSerializer::form($form))->toBe([
            'id' => 1,
            'handle' => 'f',
            'name' => 'F',
            'fields' => [],
            'notifications' => [],
            'connections' => [],
            'spamSettings' => [],
        ]);
    });
});
