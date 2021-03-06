<?php

namespace Integral\Test;

use Integral\Flysystem\Adapter\PDOAdapter;
use League\Flysystem\Filesystem;
use \PDO;

class PDOAdapterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @var PDOAdapter
     */
    protected $adapter;

    /**
     * @var Filesystem
     */
    protected $filesystem;

    /**
     * @var string
     */
    protected $table = 'files';

    /**
     * @var string
     */
    protected $pathPrefix = '/test/';

    public function setUp()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        switch($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            case 'sqlite':
                $createTableSql =
                    "CREATE TABLE {$this->table} (
                        id INTEGER PRIMARY KEY,
                        path TEXT NOT NULL UNIQUE,
                        type TEXT NOT NULL,
                        contents BLOB,
                        size INTEGER NOT NULL DEFAULT 0,
                        mimetype TEXT,
                        timestamp INTEGER NOT NULL DEFAULT 0
                    )";
                    break;

            case 'mysql':
                $createTableSql =
                    "CREATE TABLE {$this->table} (
                        id INTEGER PRIMARY KEY AUTO_INCREMENT,
                        path VARCHAR(191) NOT NULL UNIQUE,
                        type enum('file','dir') NOT NULL,
                        contents LONGBLOB,
                        size INTEGER NOT NULL DEFAULT 0,
                        mimetype VARCHAR(127),
                        timestamp INTEGER NOT NULL DEFAULT 0
                    )";
                    break;

            case 'pgsql':
                $createTableSql =
                    "CREATE TABLE {$this->table} (
                        id SERIAL PRIMARY KEY,
                        path TEXT NOT NULL UNIQUE,
                        type TEXT NOT NULL,
                        contents BYTEA,
                        size INTEGER NOT NULL DEFAULT 0,
                        mimetype TEXT,
                        timestamp INTEGER NOT NULL DEFAULT 0,
                        CONSTRAINT type_check CHECK (type='dir' or type='file')
                    )";
                    break;
        }

        $this->pdo->exec($createTableSql);

        $this->adapter = new PDOAdapter($this->pdo, $this->table, $this->pathPrefix);
        $this->filesystem = new Filesystem($this->adapter);
    }

    public function tearDown()
    {
        $this->pdo->exec("DROP TABLE {$this->table}");
    }

    protected function getTableContents($stripTimestampFromReturnedRows = true)
    {
        $statement = $this->pdo->prepare("SELECT * FROM {$this->table}");
        $statement->execute();
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($v) use ($stripTimestampFromReturnedRows) {
            unset($v['id']);
            if ($stripTimestampFromReturnedRows) {
                unset($v['timestamp']);
            }
            $v['path'] = $this->adapter->removePathPrefix($v['path']);
            $v['size'] = (int)$v['size'];
            if (is_resource($v['contents'])) {
                $fh = $v['contents'];
                $v['contents'] = stream_get_contents($fh);
                fclose($fh);
            }

            return $v;
        }, $result);
    }

    protected function assertTableContains($expected, $stripTimestampFromReturnedRows = true)
    {
        $this->assertEquals($expected, $this->getTableContents($stripTimestampFromReturnedRows));
    }

    protected function filterContents($contents)
    {
        return array_map(function ($v) {
            unset($v['timestamp']);

            return $v;
        }, $contents);
    }

    public function invalidTableNamesProvider()
    {
        return [
            ['a$b'],
            ['a/b'],
            ['a*b'],
            ['ab*'],
            ['Abcde_f&']
        ];
    }

    /**
     * @dataProvider invalidTableNamesProvider
     *
     * @expectedException \InvalidArgumentException
     */
    public function testInvalidTableName($tableName)
    {
        $adapter = new PDOAdapter($this->pdo, $tableName);
    }

    public function testBasicWrite()
    {
        $this->assertTrue($this->filesystem->createDir('foo'));

        $path1 = 'foo/bar.txt';
        $contents1 = 'ala ma kota';
        $this->assertTrue($this->filesystem->write($path1, $contents1));

        $this->assertTableContains([
            ['path' => 'foo', 'contents' => null, 'type' => 'dir', 'size' => 0, 'mimetype' => null],
            [
                'path' => $path1,
                'contents' => $contents1,
                'type' => 'file',
                'size' => strlen($contents1),
                'mimetype' => 'text/plain'
            ]
        ]);

        $path2 = 'foo/bar/baz.txt';
        $contents2 = 'kot ma ale';
        $this->assertTrue($this->filesystem->write($path2, $contents2));

        $this->assertTableContains([
            ['path' => 'foo', 'contents' => null, 'type' => 'dir', 'size' => 0, 'mimetype' => null],
            [
                'path' => $path1,
                'contents' => $contents1,
                'type' => 'file',
                'size' => strlen($contents1),
                'mimetype' => 'text/plain'
            ],
            [
                'path' => $path2,
                'contents' => $contents2,
                'type' => 'file',
                'size' => strlen($contents2),
                'mimetype' => 'text/plain'
            ]
        ]);
    }

    public function testWriteWithSpecificTimestamp()
    {
        $timestamp = \mktime(0, 0, 0, 1, 1, 2000);
        $config = array('timestamp' => $timestamp);

        $this->assertTrue($this->filesystem->createDir('foo', $config));

        $path1 = 'foo/bar.txt';
        $contents1 = 'ala ma kota';
        $this->assertTrue($this->filesystem->write($path1, $contents1, $config));

        $this->assertTableContains([
            [
                'path' => 'foo',
                'contents' => null,
                'type' => 'dir',
                'size' => 0,
                'mimetype' => null,
                'timestamp' => $timestamp
            ],
            [
                'path' => $path1,
                'contents' => $contents1,
                'type' => 'file',
                'size' => strlen($contents1),
                'mimetype' => 'text/plain',
                'timestamp' => $timestamp
            ]
        ],
            false
        );
    }

    public function testListContents()
    {
        $this->testBasicWrite();

        $contents = $this->filterContents($this->filesystem->listContents('/foo'));

        $expected = [
            [
                'dirname' => 'foo',
                'basename' => 'bar',
                'filename' => 'bar',
                'path' => 'foo/bar',
                'type' => 'dir'
            ],
            [
                'path' => 'foo/bar.txt',
                'size' => 11,
                'type' => 'file',
                'mimetype' => 'text/plain',
                'dirname' => 'foo',
                'basename' => 'bar.txt',
                'extension' => 'txt',
                'filename' => 'bar'
            ]
        ];

        $this->assertEquals($expected, $contents);

        // List /
        $contents = $this->filterContents($this->filesystem->listContents());

        $expected = [
            [
                'dirname' => '',
                'basename' => 'foo',
                'filename' => 'foo',
                'path' => 'foo',
                'type' => 'dir'
            ]
        ];

        $this->assertEquals($expected, $contents);

        $this->assertTrue($this->filesystem->write('foo2/bar.txt', 'abc'));

        $contents = $this->filterContents($this->filesystem->listContents('/'));

        $expected = [
            ['dirname' => '', 'basename' => 'foo', 'filename' => 'foo', 'path' => 'foo', 'type' => 'dir'],
            ['dirname' => '', 'basename' => 'foo2', 'filename' => 'foo2', 'path' => 'foo2', 'type' => 'dir']
        ];

        $this->assertEquals($expected, $contents);
    }

    public function testListContentsForDirWithPercentCharacter()
    {
        $this->assertTrue($this->filesystem->write('foo%/bar.txt', 'abc'));
        $this->assertTrue($this->filesystem->write('foo%/baz.txt', 'abc'));
        $this->assertTrue($this->filesystem->write('foozzz/bar.txt', 'abc'));
        $this->assertTrue($this->filesystem->write('foo%zz/barzz.txt', 'abcdef'));

        $expected = [
            [
                'path' => 'foo%/bar.txt',
                'size' => 3,
                'type' => 'file',
                'mimetype' => 'text/plain',
                'dirname' => 'foo%',
                'basename' => 'bar.txt',
                'extension' => 'txt',
                'filename' => 'bar',
            ],
            [
                'path' => 'foo%/baz.txt',
                'size' => 3,
                'type' => 'file',
                'mimetype' => 'text/plain',
                'dirname' => 'foo%',
                'basename' => 'baz.txt',
                'extension' => 'txt',
                'filename' => 'baz',
            ]
        ];

        $this->assertEquals($expected, $this->filterContents($this->filesystem->listContents('/foo%/')));
    }

    public function testSettingMimetype()
    {
        $this->assertTrue($this->filesystem->write('foo/bar.jpg', 'abc'));
        $this->assertTrue($this->filesystem->write('foo/bar.png', 'abc'));
        $this->assertTrue($this->filesystem->write('foo/bar.mp3', 'abc'));

        $this->assertSame('image/jpeg', $this->filesystem->getMimetype('/foo/bar.jpg'));
        $this->assertSame('image/png', $this->filesystem->getMimetype('/foo/bar.png'));
        $this->assertSame('audio/mpeg', $this->filesystem->getMimetype('/foo/bar.mp3'));
    }

    public function testWriteRead()
    {
        $this->filesystem->write('foo/bar.txt', 'abc123');
        $this->assertTrue($this->filesystem->write('foo/bar/baz.txt', 'def456'));
        $imageData = file_get_contents(__DIR__ . '/Fixtures/files/bar.jpg');
        $this->assertTrue($this->filesystem->write('test/image.jpg', $imageData));

        $this->assertSame('abc123', $this->filesystem->read('/foo/bar.txt'));
        $this->assertSame('def456', $this->filesystem->read('/foo/bar/baz.txt'));
        $this->assertSame($imageData, $this->filesystem->read('test/image.jpg'));
    }

    public function testWriteReadStream()
    {
        $imageStream = fopen(__DIR__ . '/Fixtures/files/bar.jpg', 'r');
        $this->assertTrue($this->filesystem->writeStream('foo/bar.jpg', $imageStream));
        $fileStream = fopen(__DIR__ . '/Fixtures/files/foo.txt', 'r');
        $this->assertTrue($this->filesystem->writeStream('foo.txt', $fileStream));

        $imageReadStream = $this->filesystem->readStream('/foo/bar.jpg');
        $fileReadStream = $this->filesystem->readStream('/foo.txt');

        rewind($imageStream);
        rewind($fileStream);

        $this->assertTrue(is_resource($imageReadStream));
        $this->assertTrue(is_resource($fileReadStream));
        $this->assertSame(stream_get_contents($imageStream), stream_get_contents($imageReadStream));
        $this->assertSame(stream_get_contents($fileStream), stream_get_contents($fileReadStream));
    }

    public function testUpdate()
    {
        $this->assertTrue($this->filesystem->write('foo/bar.txt', 'abc'));
        $this->assertSame('abc', $this->filesystem->read('foo/bar.txt'));
        $this->assertTrue($this->filesystem->update('foo/bar.txt', 'def'));
        $this->assertSame('def', $this->filesystem->read('foo/bar.txt'));
    }

    public function testUpdateStream()
    {
        $this->assertTrue($this->filesystem->write('foo/bar.txt', 'abc'));
        $this->assertSame('abc', $this->filesystem->read('foo/bar.txt'));

        $fileStream = fopen(__DIR__ . '/Fixtures/files/foo.txt', 'r');
        $this->assertTrue($this->filesystem->updateStream('foo/bar.txt', $fileStream));
        rewind($fileStream);
        $this->assertSame(stream_get_contents($fileStream), $this->filesystem->read('foo/bar.txt'));
    }

    public function testRenameOfNestedObjectsInDirectory()
    {
        $this->assertTrue($this->filesystem->createDir('foo'));
        $this->assertTrue($this->filesystem->write('foo/bar.txt', 'abc'));
        $this->assertTrue($this->filesystem->createDir('foo/baz'));
        $this->assertTrue($this->filesystem->write('foo/baz/buzz.txt', 'def'));

        $this->assertTrue($this->filesystem->has('foo'));
        $this->assertFalse($this->filesystem->has('foo/bar'));
        $this->assertTrue($this->filesystem->has('foo/baz'));

        $this->filesystem->rename('foo', 'renamed');

        $this->assertFalse($this->filesystem->has('foo'));
        $this->assertFalse($this->filesystem->has('foo/baz'));
        $this->assertTrue($this->filesystem->has('renamed'));
        $this->assertTrue($this->filesystem->has('renamed/baz'));

        $this->assertEquals('abc', $this->filesystem->read('renamed/bar.txt'));
        $this->assertEquals('def', $this->filesystem->read('renamed/baz/buzz.txt'));
    }

    public function testDeleteOfNestedObjectsInDirectory()
    {
        $this->assertTrue($this->filesystem->createDir('foo'));
        $this->assertTrue($this->filesystem->write('foo/bar.txt', 'abc'));
        $this->assertTrue($this->filesystem->createDir('foo/baz'));
        $this->assertTrue($this->filesystem->write('foo/baz/buzz.txt', 'def'));

        $this->assertTrue($this->filesystem->has('foo'));
        $this->assertTrue($this->filesystem->has('foo/baz'));

        $this->filesystem->deleteDir('foo');

        $this->assertEmpty($this->getTableContents());
    }

    public function testCopy()
    {
        $this->assertTrue($this->filesystem->createDir('foo'));
        $this->assertTrue($this->filesystem->write('foo/bar.txt', 'abc'));

        $this->assertFalse($this->adapter->copy('foo/nonexisting_bar.txt', 'copied.txt'));

        $this->assertTrue($this->filesystem->copy('foo/bar.txt', 'copied.txt'));
        $this->assertSame($this->filesystem->read('foo/bar.txt'), $this->filesystem->read('copied.txt'));
    }

    public function testDelete()
    {
        $this->assertTrue($this->filesystem->createDir('foo'));
        $this->assertTrue($this->filesystem->write('foo/bar.txt', 'abc'));
        $this->assertTrue($this->filesystem->has('foo/bar.txt'));
        $this->assertTrue($this->filesystem->delete('foo/bar.txt'));
        $this->assertFalse($this->filesystem->has('foo/bar.txt'));
    }

    public function testHasForNonexistingPath()
    {
        $this->assertFalse($this->filesystem->has('foo/nonexisting_bar.txt'));
    }

    public function testReadStreamForNonexistingPath()
    {
        $this->assertFalse($this->adapter->readStream('foo/nonexisting_bar.txt'));
    }

    public function testMetadata()
    {
        $this->assertTrue($this->filesystem->write('foo/bar.jpg', 'abc'));

        $this->assertTrue($this->filesystem->has('foo/bar.jpg'));
        $this->assertEquals('image/jpeg', $this->filesystem->getMimetype('foo/bar.jpg'));
        $this->assertEquals(3, $this->filesystem->getSize('foo/bar.jpg'));
        $this->assertTrue($this->filesystem->getTimestamp('foo/bar.jpg') > 1448170000);
    }
}
