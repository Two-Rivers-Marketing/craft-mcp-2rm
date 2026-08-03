<?php

declare(strict_types=1);

namespace twoRivers\craft\Mcp\tools;

use Craft;
use craft\elements\User;
use craft\models\UserGroup;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\RequestContext;
use twoRivers\craft\Mcp\attributes\McpToolMeta;
use twoRivers\craft\Mcp\enums\ToolCategory;
use twoRivers\craft\Mcp\support\Response;
use twoRivers\craft\Mcp\support\SafeExecution;

/**
 * User MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class UserTools {
    /**
     * List users.
     */
    #[McpTool(
        name: 'list_users',
        description: 'List users from Craft CMS. Filter by group handle, status, email.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listUsers(
        ?string $group = null,
        ?string $status = null,
        ?string $email = null,
        int $limit = 50,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($group, $status, $email, $limit): array {
            $query = User::find()->limit($limit);

            if ($group !== null) {
                $query->group($group);
            }
            if ($status !== null) {
                $query->status($status);
            }
            if ($email !== null) {
                $query->email($email);
            }

            $users = $query->all();
            $results = array_map($this->serializeUser(...), $users);

            return Response::list('users', $results);
        });
    }

    /**
     * Get a single user by ID, email or username.
     */
    #[McpTool(
        name: 'get_user',
        description: 'Get a single user by id, email or username. At least one of those is required. Matches users in any status, including inactive and suspended.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getUser(
        ?int $id = null,
        ?string $email = null,
        ?string $username = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($id, $email, $username): array {
            if ($id === null && $email === null && $username === null) {
                throw new ToolCallException('At least one of id, email or username is required');
            }

            $query = User::find()->status(null);

            if ($id !== null) {
                $query->id($id);
            }
            if ($email !== null) {
                $query->email($email);
            }
            if ($username !== null) {
                $query->username($username);
            }

            $user = $query->one();

            if (!$user instanceof User) {
                throw new ToolCallException('No user found matching the given id, email or username');
            }

            return Response::found('user', $this->serializeUser($user));
        });
    }

    /**
     * Create a user.
     */
    #[McpTool(
        name: 'create_user',
        description: 'Create a user in Craft CMS. groups is a JSON array of user group handles. username defaults to the email address. Without activate the account is created inactive (no credentials); activate: true activates it immediately so the user can be given a password.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function createUser(
        string $email,
        ?string $username = null,
        ?string $fullName = null,
        ?string $groups = null,
        bool $admin = false,
        bool $activate = false,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($email, $username, $fullName, $groups, $admin, $activate): array {
            $resolvedGroups = $this->resolveGroups($groups);

            $user = new User();
            $user->email = $email;
            $user->username = $username ?? $email;
            $user->admin = $admin;

            if ($fullName !== null) {
                $user->fullName = $fullName;
            }

            if (!Craft::$app->getElements()->saveElement($user)) {
                throw new ToolCallException('Failed to save user: ' . json_encode($user->getErrors()));
            }

            if ($resolvedGroups !== null) {
                $this->applyGroups($user, $resolvedGroups);
            }

            if ($activate) {
                Craft::$app->getUsers()->activateUser($user);
            }

            return Response::success(['user' => $this->serializeUser($user)]);
        });
    }

    /**
     * Update a user.
     */
    #[McpTool(
        name: 'update_user',
        description: 'Update a user by id. groups is a JSON array of user group handles and replaces the user\'s current groups. Only the parameters you pass change; omitted fields are untouched.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    public function updateUser(
        int $id,
        ?string $email = null,
        ?string $username = null,
        ?string $fullName = null,
        ?string $groups = null,
        ?bool $admin = null,
        ?RequestContext $context = null,
    ): array {
        return SafeExecution::run(function () use ($id, $email, $username, $fullName, $groups, $admin): array {
            $user = User::find()->id($id)->status(null)->one();

            if (!$user instanceof User) {
                throw new ToolCallException("User with ID {$id} not found");
            }

            $resolvedGroups = $this->resolveGroups($groups);

            if ($email !== null) {
                $user->email = $email;
            }
            if ($username !== null) {
                $user->username = $username;
            }
            if ($fullName !== null) {
                // Clear the derived parts so Craft re-splits the new full name on save.
                $user->fullName = $fullName;
                $user->firstName = null;
                $user->lastName = null;
            }
            if ($admin !== null) {
                $user->admin = $admin;
            }

            if (!Craft::$app->getElements()->saveElement($user)) {
                throw new ToolCallException('Failed to save user: ' . json_encode($user->getErrors()));
            }

            if ($resolvedGroups !== null) {
                $this->applyGroups($user, $resolvedGroups);
            }

            return Response::success(['user' => $this->serializeUser($user)]);
        });
    }

    /**
     * Resolve a JSON array of group handles into user group models.
     *
     * @return UserGroup[]|null null when no groups were requested
     */
    private function resolveGroups(?string $groups): ?array {
        if ($groups === null) {
            return null;
        }

        $handles = json_decode($groups, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($handles)) {
            throw new ToolCallException('Invalid JSON in groups parameter (expected a JSON array of user group handles)');
        }

        $service = Craft::$app->getUserGroups();
        $resolved = [];

        foreach ($handles as $handle) {
            $group = $service->getGroupByHandle((string) $handle);

            if ($group === null) {
                throw new ToolCallException("User group with handle '{$handle}' not found");
            }

            $resolved[] = $group;
        }

        return $resolved;
    }

    /**
     * Persist a user's group membership. Group assignment is a separate write from
     * saveElement() — User::setGroups() only updates the in-memory cache.
     *
     * @param UserGroup[] $groups
     */
    private function applyGroups(User $user, array $groups): void {
        $groupIds = array_map(static fn (UserGroup $group): int => (int) $group->id, $groups);

        if (!Craft::$app->getUsers()->assignUserToGroups((int) $user->id, $groupIds)) {
            throw new ToolCallException('Failed to assign user groups');
        }

        $user->setGroups($groups);
    }

    /**
     * Serialize a user to array.
     */
    private function serializeUser(User $user): array {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'fullName' => $user->fullName,
            'admin' => $user->admin,
            'status' => $user->getStatus(),
            'groups' => array_map(fn ($g) => $g->handle, $user->getGroups()),
            'lastLoginDate' => $user->lastLoginDate?->format('c'),
            'dateCreated' => $user->dateCreated?->format('c'),
        ];
    }
}
