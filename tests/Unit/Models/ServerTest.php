<?php

namespace App\Tests\Unit\Models;

use App\Enums\ContainerStatus;
use App\Models\Node;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

class ServerTest extends TestCase
{
    #[DataProvider('sftpUrlDataProvider')]
    public function test_sftp_url_points_at_the_given_directory(?string $directory, string $expectedPath): void
    {
        $server = $this->serverWithNode();

        $this->assertSame("sftp://user.abcdefgh@node.example.com:2022{$expectedPath}", $server->getSftpUrl('user', $directory));
    }

    public static function sftpUrlDataProvider(): array
    {
        return [
            'no directory' => [null, ''],
            'root' => ['/', ''],
            'single directory' => ['/data', '/data/'],
            'directory without a leading slash' => ['data', '/data/'],
            'directory with a trailing slash' => ['data/', '/data/'],
            'nested directory' => ['/data/world', '/data/world/'],
            'directory with encoded parts' => ['/data/my world', '/data/my%20world/'],
        ];
    }

    public function test_sftp_url_uses_the_node_sftp_alias_when_set(): void
    {
        $server = $this->serverWithNode(sftpAlias: 'sftp.example.com');

        $this->assertSame('sftp://user.abcdefgh@sftp.example.com:2022/data/', $server->getSftpUrl('user', '/data'));
    }

    public function test_sftp_url_encodes_the_username(): void
    {
        $server = $this->serverWithNode();

        $this->assertSame('sftp://user%20name.abcdefgh@node.example.com:2022/data/', $server->getSftpUrl('user name', '/data'));
    }

    public function test_status_and_resources_share_a_single_daemon_request(): void
    {
        $daemon = Mockery::mock(DaemonServerRepository::class);
        $daemon->expects('setServer')->andReturnSelf();
        $daemon->expects('getDetails')->andReturn([
            'state' => ContainerStatus::Running->value,
            'utilization' => ['memory_bytes' => 1024],
        ]);
        $this->instance(DaemonServerRepository::class, $daemon);

        $server = $this->serverWithNode();

        $this->assertSame(ContainerStatus::Running, $server->retrieveStatus());
        $this->assertSame(['memory_bytes' => 1024], $server->retrieveResources());
    }

    private function serverWithNode(string $fqdn = 'node.example.com', ?string $sftpAlias = null): Server
    {
        $node = new Node();
        $node->fqdn = $fqdn;
        $node->daemon_sftp_alias = $sftpAlias;
        $node->daemon_sftp = 2022;
        $node->maintenance_mode = false;

        $server = new Server();
        $server->uuid = 'c7a5f3e1-0d4b-4a6e-9b8f-2d1c3e4f5a6b';
        $server->uuid_short = 'abcdefgh';
        $server->setRelation('node', $node);

        return $server;
    }
}
