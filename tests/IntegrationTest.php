<?php

namespace GiorgioImSocket\Tests;

use GiorgioImSocket\Contracts\SocketIdentity;
use GiorgioImSocket\Http\Controllers\ImController;
use GiorgioImSocket\Services\ImService;
use GiorgioImSocket\Services\SocketAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class IntegrationTest extends TestCase
{
    public function test_package_registers_only_im_routes_and_initialises_existing_users(): void
    {
        $alice = Account::create(['display_name' => 'Alice']);
        $this->actingAs($alice)->getJson('/api/im/init')->assertOk()
            ->assertJsonPath('init.user.id', $alice->getKey())
            ->assertJsonPath('init.user.username', 'Alice')->assertJsonCount(1, 'init.friend');
        $actions = collect(Route::getRoutes())->map(fn ($route) => $route->getActionName());
        $this->assertTrue($actions->contains(ImController::class.'@init'));
        $this->assertFalse($actions->contains(fn ($action) => str_contains($action, 'AuthController')));
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => in_array($route->uri(), ['api/im/login', 'api/im/register', 'api/im/logout'])));
        $this->assertDatabaseCount('im_groups', 0);
        $this->assertDatabaseCount('im_notices', 0);
    }

    public function test_authentication_is_required_without_any_login_implementation(): void
    {
        $this->getJson('/api/im/init')->assertUnauthorized();
    }

    public function test_custom_user_model_supports_profiles_friendships_messages_and_receipts(): void
    {
        $alice = Account::create(['display_name' => 'Alice']);
        $bob = Account::create(['display_name' => 'Bob']);
        $this->actingAs($alice)->patchJson('/api/im/user', ['username' => 'Alicia'])->assertOk()->assertJsonPath('username', 'Alicia');
        $this->getJson('/api/im/users?q=Bob')->assertOk()->assertJsonPath('0.id', $bob->getKey());
        $this->postJson('/api/im/friend-requests', ['id' => 999])->assertUnprocessable();
        $this->postJson('/api/im/friend-requests', ['id' => $bob->getKey()])->assertOk();
        $notice = DB::table('im_notices')->value('id');
        $category = app(ImService::class)->category($bob->getKey());
        $this->actingAs($bob)->postJson('/api/im/notices/'.$notice, ['action' => 'accept', 'groupId' => $category])->assertOk();
        $mid = (string) Str::uuid();
        $this->actingAs($alice)->postJson('/api/im/messages', ['type' => 'friend', 'id' => $bob->getKey(), 'mid' => $mid, 'content' => 'Hello'])->assertOk();
        $this->postJson('/api/im/conversations/typing', ['type' => 'friend', 'id' => $bob->getKey(), 'typing' => true])->assertOk();
        $this->actingAs($bob)->getJson('/api/im/messages?type=friend&id='.$alice->getKey())->assertOk()->assertJsonPath('messages.0.username', 'Alicia');
        $this->postJson('/api/im/messages/read', ['type' => 'friend', 'id' => $alice->getKey(), 'mids' => [$mid]])->assertOk();
        $this->assertDatabaseHas('im_message_reads', ['user_id' => $bob->getKey()]);
    }

    public function test_package_migrations_use_the_configured_user_table_and_preserve_existing_data(): void
    {
        $alice = Account::create(['display_name' => 'Alice']);
        app(ImService::class)->profile($alice->getKey());
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $foreign = DB::select("PRAGMA foreign_key_list('im_profiles')")[0];
        $this->assertSame(['accounts', 'account_id'], [$foreign->table, $foreign->to]);
        $this->assertDatabaseHas('im_profiles', ['user_id' => $alice->getKey()]);
    }

    public function test_default_tickets_check_user_binding_expiry_and_connection_lifetime(): void
    {
        $alice = Account::create(['display_name' => 'Alice']);
        config(['im.socket_ticket_ttl' => 1, 'im.socket_session_ttl' => 10]);
        $ticket = $this->actingAs($alice)->postJson('/api/im/socket-ticket')->assertOk()->json('token');
        $authenticate = app(SocketAuthenticator::class);
        $this->assertSame($alice->getKey(), $authenticate(['token' => $ticket, 'user_id' => 999]));
        $this->assertNull($authenticate(['token' => $ticket.'changed']));
        $this->travel(2)->seconds();
        $this->assertNull($authenticate(['token' => $ticket]));
        $this->assertSame($alice->getKey(), $authenticate(['token' => $ticket], true));
        $this->travel(9)->seconds();
        $this->assertNull($authenticate(['token' => $ticket], true));
    }

    public function test_custom_identity_adapter_can_revoke_an_established_connection(): void
    {
        $alice = Account::create(['display_name' => 'Alice']);
        $identity = new class implements SocketIdentity
        {
            public bool $active = true;

            public function claims(Request $request): array
            {
                return ['session' => 'custom-session'];
            }

            public function resolve(array $claims): ?int
            {
                return $this->active && ($claims['session'] ?? null) === 'custom-session' ? ($claims['user_id'] ?? null) : null;
            }
        };
        $this->app->instance(SocketIdentity::class, $identity);
        $ticket = $this->actingAs($alice)->postJson('/api/im/socket-ticket')->assertOk()->json('token');
        $authenticate = app(SocketAuthenticator::class);
        $this->assertSame($alice->getKey(), $authenticate(['token' => $ticket]));
        $identity->active = false;
        $this->assertNull($authenticate(['token' => $ticket], true));
        $this->postJson('/api/im/socket-ticket')->assertForbidden();
    }
}
