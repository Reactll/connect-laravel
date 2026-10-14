<?php

namespace Reactll\Connect\Tests;

use Illuminate\Support\Facades\Http;
use Reactll\Connect\Connect;
use Reactll\Connect\Facades\Connect as ReactllConnect;

class ModulesTest extends TestCase
{
    public function test_all_modules_are_on_by_default(): void
    {
        $this->assertSame(['visitors' => true, 'events' => true, 'forms' => true, 'sales' => true], ReactllConnect::modules());
        $this->assertSame('<script src="https://connect.example.test/v1/c.js" data-site="'.self::KEY.'" defer></script>', ReactllConnect::script());
    }

    public function test_visitors_off_means_no_script_and_events_off_marks_it(): void
    {
        config(['reactll-connect.modules.events' => 'false']);
        $this->assertStringContainsString('data-events="off"', ReactllConnect::script());

        config(['reactll-connect.modules.visitors' => false]);
        $this->assertSame('', ReactllConnect::script());
    }

    public function test_forms_off_makes_the_submission_a_no_op(): void
    {
        config(['reactll-connect.modules.forms' => false]);
        Http::fake();

        $result = ReactllConnect::submit('Contact', ['email' => 'a@b.test']);
        $this->assertTrue($result->ok);
        $this->assertSame('switched_off', $result->reason);
        $this->assertTrue(ReactllConnect::submission('Contact', ['email' => 'a@b.test']));
        ReactllConnect::submitAfterResponse('Contact', ['email' => 'a@b.test']);
        $this->app->terminate();
        Http::assertNothingSent();
    }

    public function test_a_submission_still_goes_when_forms_is_on(): void
    {
        Http::fakeSequence()->push(['ok' => true, 'stored' => 1], 201)->push(['error' => 'host'], 409);

        $this->assertTrue(ReactllConnect::submission('Contact', ['email' => 'a@b.test', 'password' => 'x']));
        Http::assertSent(fn ($r) => $r->url() === 'https://connect.example.test/v1/submissions' && $r['fields'] === ['email' => 'a@b.test']);

        $this->assertSame('host', ReactllConnect::submit('Contact', ['email' => 'a@b.test'])->reason);
    }

    public function test_the_health_answer_and_heartbeat_report_modules_and_capabilities(): void
    {
        config(['reactll-connect.modules.sales' => false, 'reactll-connect.sends_leads' => false]);
        $payload = app(Connect::class)->healthPayload();
        $this->assertSame(['visitors' => true, 'events' => true, 'forms' => true, 'sales' => false], $payload['modules']);
        $this->assertSame(['forms' => false, 'sales' => false], $payload['capabilities']);
        $this->assertArrayNotHasKey('sales', $payload);

        config(['reactll-connect.modules.sales' => true, 'reactll-connect.sends_leads' => true]);
        Http::fake(['*' => Http::response(['site' => ['name' => 'Shop']])]);
        $this->artisan('reactll:heartbeat')->assertSuccessful();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/heartbeat')
            && $r['capabilities'] === ['forms' => true, 'sales' => true]
            && $r['sales']['backfill'] === 'none'
            && $r['modules']['sales'] === true);
    }

    public function test_the_signed_health_endpoint_answers_with_the_modules(): void
    {
        $ts = (string) time();
        $this->get('/api/reactll/health', ['X-Reactll-Timestamp' => $ts, 'X-Reactll-Signature' => hash_hmac('sha256', $ts.'.health', self::SECRET)])
            ->assertOk()
            ->assertJsonPath('modules.sales', true)
            ->assertJsonPath('capabilities.sales', true);

        $this->get('/api/reactll/health', ['X-Reactll-Timestamp' => $ts, 'X-Reactll-Signature' => str_repeat('f', 64)])->assertStatus(401);
    }
}
