<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Profile\Service\ImageUploader;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * FR-062, FR-071 and NFR-066 — uploads, and what must never get through one.
 *
 * Uploads are the classic route to remote code execution in a PHP application, so the negative
 * tests here are the important ones. The one that would catch a real mistake is
 * `testFileThatIsNotAnImageIsRejected()`: it uploads PHP source named `.png`, which passes an
 * extension check and a client-supplied `Content-Type` check, and is refused only because
 * `ImageUploader` parses the file.
 *
 * The served-image tests exist because storing a file safely is only half of NFR-066. The other
 * half is that the only way to read one is through a controller that has already decided who may.
 */
final class ImageUploadTest extends ProfileWebTestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function testPlayerUploadsAPhotoAndItIsServedThroughTheController(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $profileId = (int) $profile->getId();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/account/profile');
        $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Dana Parent',
            'update_profile_form[photo]' => $this->upload('photo.png'),
        ]);

        self::assertResponseRedirects('/account/profile');

        $saved = $this->reloadProfile($profileId);
        self::assertTrue($saved->hasPhoto());

        // A path relative to the private upload root, never a URL: the serving strategy must not
        // be baked into the row.
        self::assertStringStartsWith('photos/', (string) $saved->getPhotoPath());
        self::assertStringNotContainsString('http', (string) $saved->getPhotoPath());
        self::assertNotNull($saved->getPhotoThumbnailPath());

        // And the bytes come back only through the controller.
        $this->client->request('GET', \sprintf('/media/players/%d/photo', $profileId));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
        // The browser must not second-guess the type we declared.
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
    }

    public function testAnotherFamilyCannotLoadAChildsPhoto(): void
    {
        $parent = $this->createParent();
        $child = $this->createChildProfile($parent, 'Mateo Parent');

        $stored = $this->storeRealPhoto($child);
        self::assertNotNull($stored);

        $this->createParent(self::OTHER_PARENT_EMAIL, 'Sam Stranger');

        $this->submitLogin(self::OTHER_PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/media/players/%d/photo', $child->getId()));

        // A photograph of a named child is the most identifying file the platform holds. Guarded
        // by `ProfileVoter`, so only the family and an actively associated trainer can read it.
        self::assertResponseStatusCodeSame(403);
    }

    public function testTrainerCanLoadAPhotoOfAPlayerTheyActivelyTrain(): void
    {
        $parent = $this->createParent();
        $child = $this->createChildProfile($parent, 'Mateo Parent');
        $this->createAssociation($child);

        self::assertNotNull($this->storeRealPhoto($child));

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', \sprintf('/media/players/%d/photo', $child->getId()));

        self::assertResponseIsSuccessful();
    }

    public function testAnonymousVisitorCannotLoadAnyStoredImage(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        self::assertNotNull($this->storeRealPhoto($profile));

        $this->client->request('GET', \sprintf('/media/players/%d/photo', $profile->getId()));

        // `access_control` on `^/media`, before any voter runs.
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testMissingFileIsNotFoundRatherThanAServerError(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);

        // A row pointing at bytes that are gone: a restored database, a failed deploy. A broken
        // avatar must not take the page with it.
        $profile->setPhoto('photos/2026/01/missing.png', null, new \DateTimeImmutable());
        $this->save($profile);

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', \sprintf('/media/players/%d/photo', $profile->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testFileThatIsNotAnImageIsRejected(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $profileId = (int) $profile->getId();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/account/profile');
        $crawler = $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Dana Parent',
            // PHP source called `.png`. Passes an extension check and a client-supplied MIME
            // check; refused because the uploader parses the file.
            'update_profile_form[photo]' => $this->upload('evil.png', real: false),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('could not be read as an image', $crawler->text());
        self::assertFalse($this->reloadProfile($profileId)->hasPhoto());
    }

    public function testExtensionThatDisagreesWithTheContentIsRejected(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $profileId = (int) $profile->getId();

        // Genuinely a PNG, presented as a JPEG. NFR-066's "type-validated by content rather than
        // extension" cuts both ways: a mismatch is its own rejection, with its own message.
        //
        // The mislabelling has to be carried by the file's *name on disk*, not by the
        // UploadedFile's client name: DomCrawler copies the file into its own temporary
        // location and re-derives the client name from that path, so a client name passed here
        // never reaches the server. Naming the real PNG `.jpg` is what the uploader actually
        // sees from a browser that sent a renamed file.
        $upload = $this->upload('holiday.jpg');

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/account/profile');
        $crawler = $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Dana Parent',
            'update_profile_form[photo]' => $upload,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('but its contents are', $crawler->text());
        self::assertFalse($this->reloadProfile($profileId)->hasPhoto());
    }

    public function testOversizeFileIsRejected(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $profileId = (int) $profile->getId();

        // Comfortably over the 2MB limit, and a real PNG — so it is refused for its size and not
        // for its content.
        $path = $this->createOversizePngFile();
        $this->temporaryFiles[] = $path;

        self::assertGreaterThan(ImageUploader::MAX_BYTES, (int) filesize($path));

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/account/profile');
        $crawler = $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Dana Parent',
            'update_profile_form[photo]' => new UploadedFile($path, 'huge.png', 'image/png', test: true),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The limit is 2 MB', $crawler->text());
        self::assertFalse($this->reloadProfile($profileId)->hasPhoto());
    }

    /**
     * G-24: SVG is listed by FR-071 and refused anyway.
     *
     * An SVG is a script-bearing document, not a raster image — served inline it executes. The
     * requirement's own "auto-resize if larger" implies raster, and sanitizing SVG properly is a
     * project of its own.
     */
    public function testSvgIsRefused(): void
    {
        $path = sys_get_temp_dir() . '/' . uniqid('upload-', true) . '-logo.svg';
        file_put_contents(
            $path,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );
        $this->temporaryFiles[] = $path;

        $this->submitLogin(self::TRAINER_EMAIL);
        $this->client->request('GET', '/trainer/branding');
        $crawler = $this->client->submitForm('Save branding', [
            'branding_form[logo]' => new UploadedFile($path, 'logo.svg', 'image/svg+xml', test: true),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('could not be read as an image', $crawler->text());
    }

    public function testReplacingAPhotoRemovesThePreviousFile(): void
    {
        $parent = $this->createParent();
        $profile = $this->createSelfProfile($parent);
        $profileId = (int) $profile->getId();

        $this->submitLogin(self::PARENT_EMAIL);
        $this->client->request('GET', '/account/profile');
        $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Dana Parent',
            'update_profile_form[photo]' => $this->upload('first.png'),
        ]);

        $first = (string) $this->reloadProfile($profileId)->getPhotoPath();
        $uploader = static::getContainer()->get(ImageUploader::class);
        $firstAbsolute = $uploader->absolutePathFor($first);
        self::assertNotNull($firstAbsolute);
        self::assertFileExists($firstAbsolute);

        $this->client->request('GET', '/account/profile');
        $this->client->submitForm('Save', [
            'update_profile_form[name]' => 'Dana Parent',
            'update_profile_form[photo]' => $this->upload('second.png'),
        ]);

        $second = (string) $this->reloadProfile($profileId)->getPhotoPath();
        self::assertNotSame($first, $second);

        // FR-062's "replaces the previous photo" has to include the bytes: a photo the user
        // believes they replaced but which is still readable is a privacy failure.
        self::assertFileDoesNotExist($firstAbsolute);
    }

    private function upload(string $filename, bool $real = true): UploadedFile
    {
        $path = $real ? $this->createPngFile($filename) : $this->createFakeImageFile($filename);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $filename, 'image/png', test: true);
    }

    /**
     * Writes a real photo onto a profile through the uploader, for the read-side tests.
     *
     * Going through `ImageUploader` rather than writing a path by hand means the served bytes and
     * the stored path agree, which is the thing those tests are about.
     */
    private function storeRealPhoto(\App\Profile\Entity\PlayerProfile $profile): ?string
    {
        $path = $this->createPngFile('stored.png');
        $this->temporaryFiles[] = $path;

        $stored = static::getContainer()
            ->get(ImageUploader::class)
            ->storeProfilePhoto(new UploadedFile($path, 'stored.png', 'image/png', test: true));

        $managed = $this->managed($profile, \App\Profile\Entity\PlayerProfile::class);
        $managed->setPhoto($stored->path, $stored->thumbnailPath, new \DateTimeImmutable());
        $this->save($managed);

        return $stored->path;
    }

    /**
     * A PNG larger than the 2MB limit.
     *
     * Random pixel noise, because PNG is lossless and compresses flat colour almost to nothing —
     * a large *blank* image would still be a small file and would not exercise the limit.
     */
    private function createOversizePngFile(): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('upload-', true) . '-huge.png';

        $size = 1400;
        $image = imagecreatetruecolor($size, $size);
        self::assertNotFalse($image);

        for ($x = 0; $x < $size; $x += 2) {
            for ($y = 0; $y < $size; $y += 2) {
                $colour = imagecolorallocate($image, ($x * 7) % 256, ($y * 13) % 256, ($x * $y) % 256);
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, (int) $colour);
            }
        }

        imagepng($image, $path, 0);
        imagedestroy($image);

        return $path;
    }
}
