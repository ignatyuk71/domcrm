<?php

namespace Tests\Feature\Inbox;

use App\Models\AiPhoto;
use App\Services\Ai\Gpt\DeliveryImage;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GptDeliveryImageTest extends TestCase
{
    private string $fixturePublic;
    private string $originalPublic;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('gd')) $this->markTestSkipped('Для геометричних fixtures потрібен GD.');
        $this->originalPublic = public_path();
        $this->fixturePublic = sys_get_temp_dir().'/gpt-delivery-'.bin2hex(random_bytes(8));
        mkdir($this->fixturePublic.'/ai-gallery', 0700, true);
        $this->app->usePublicPath($this->fixturePublic);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->originalPublic)) $this->app->usePublicPath($this->originalPublic);
            if (isset($this->fixturePublic)) File::deleteDirectory($this->fixturePublic);
        } finally {
            parent::tearDown();
        }
    }

    public function test_large_opaque_png_is_delivered_as_smaller_jpeg_without_changing_the_source(): void
    {
        $photo = $this->png('large.png');
        $source = public_path($photo->path);
        $before = hash_file('sha256', $source);

        $url = app(DeliveryImage::class)->url($photo);

        $this->assertNotSame($photo->url(), $url);
        $target = $this->deliveredPath($url);
        $image = getimagesize($target);
        $this->assertSame('image/jpeg', $image['mime']);
        $this->assertSame([1600, 800], [$image[0], $image[1]]);
        $this->assertLessThan(filesize($source) * 0.9, filesize($target));
        $this->assertSame($before, hash_file('sha256', $source));
        $this->assertSame([], glob(dirname($target).'/.gpt-*'));
    }

    public function test_cached_copy_is_reused_and_new_source_content_gets_a_new_url_even_with_the_same_mtime(): void
    {
        $photo = $this->png('cache.png');
        $delivery = app(DeliveryImage::class);
        $first = $delivery->url($photo);
        $target = $this->deliveredPath($first);
        $this->assertNotSame($photo->url(), $first);
        touch($target, 1_600_000_000);
        $source = public_path($photo->path);
        $oldMtime = filemtime($source);
        $oldSource = hash_file('sha256', $source);
        $oldCopy = hash_file('sha256', $target);

        $this->assertSame($first, $delivery->url($photo));
        clearstatcache(true, $target);
        $this->assertSame(1_600_000_000, filemtime($target));
        $this->assertSame($oldCopy, hash_file('sha256', $target));

        $this->png('cache.png', 2400, 1200, 1);
        touch($source, $oldMtime);
        clearstatcache(true, $source);
        $this->assertNotSame($oldSource, hash_file('sha256', $source));
        $second = $delivery->url($photo);
        $this->assertNotSame($first, $second);
        $this->assertFileExists($this->deliveredPath($second));
        $this->assertSame($oldCopy, hash_file('sha256', $target));
    }

    public function test_size_guide_keeps_original_resolution_and_url(): void
    {
        $photo = $this->png('size-grid.png');
        $photo->purpose = 'size_guide';
        $before = hash_file('sha256', public_path($photo->path));

        $this->assertSame($photo->url(), app(DeliveryImage::class)->url($photo));

        $this->assertSame($before, hash_file('sha256', public_path($photo->path)));
        $this->assertDirectoryDoesNotExist(public_path('ai-gallery/gpt-delivery'));
    }

    public function test_truncated_cached_jpeg_is_rebuilt_instead_of_being_sent(): void
    {
        $photo = $this->png('broken-cache.png');
        $delivery = app(DeliveryImage::class);
        $url = $delivery->url($photo);
        $path = $this->deliveredPath($url);
        $complete = file_get_contents($path);
        file_put_contents($path, substr($complete, 0, -2));
        clearstatcache(true, $path);
        $this->assertNotFalse(getimagesize($path));

        $this->assertSame($url, $delivery->url($photo));

        $this->assertSame($complete, file_get_contents($path));
        $this->assertSame([], glob(dirname($path).'/.gpt-*'));
    }

    public function test_already_small_png_and_webp_keep_their_original_urls_and_bytes(): void
    {
        $png = $this->png('small.png', 320, 160);
        $this->assertLessThan(350 * 1024, filesize(public_path($png->path)));
        $photos = [$png];
        if (function_exists('imagewebp')) {
            $image = $this->geometry(320, 160);
            imagewebp($image, public_path('ai-gallery/small.webp'), 85);
            imagedestroy($image);
            $photos[] = $this->photo('ai-gallery/small.webp');
        }
        foreach ($photos as $photo) {
            $before = hash_file('sha256', public_path($photo->path));
            $this->assertSame($photo->url(), app(DeliveryImage::class)->url($photo));
            $this->assertSame($before, hash_file('sha256', public_path($photo->path)));
        }
        $this->assertDirectoryDoesNotExist(public_path('ai-gallery/gpt-delivery'));
    }

    public function test_transparent_truecolor_and_palette_png_and_gif_are_not_flattened_or_reencoded(): void
    {
        $truecolor = $this->geometry(1800, 900);
        imagealphablending($truecolor, false);
        imagesavealpha($truecolor, true);
        // Один напівпрозорий піксель наприкінці перевіряє весь растр, а не лише його кут.
        imagesetpixel($truecolor, 1799, 899, imagecolorallocatealpha($truecolor, 255, 0, 0, 64));
        imagepng($truecolor, public_path('ai-gallery/alpha.png'));
        imagedestroy($truecolor);

        $palette = imagecreate(1800, 900);
        imagecolorallocate($palette, 255, 255, 255);
        $semi = imagecolorallocatealpha($palette, 255, 0, 0, 64);
        imagefilledrectangle($palette, 0, 0, 899, 899, $semi);
        $this->assertSame(-1, imagecolortransparent($palette));
        imagepng($palette, public_path('ai-gallery/palette-alpha.png'));
        imagegif($palette, public_path('ai-gallery/large.gif'));
        imagedestroy($palette);
        $decoded = imagecreatefrompng(public_path('ai-gallery/palette-alpha.png'));
        $this->assertFalse(imageistruecolor($decoded));
        $this->assertSame(-1, imagecolortransparent($decoded));
        $this->assertSame(64, imagecolorsforindex($decoded, imagecolorat($decoded, 10, 10))['alpha']);
        imagedestroy($decoded);

        foreach (['alpha.png', 'palette-alpha.png', 'large.gif'] as $name) {
            $photo = $this->photo('ai-gallery/'.$name);
            $before = hash_file('sha256', public_path($photo->path));
            $this->assertSame($photo->url(), app(DeliveryImage::class)->url($photo), $name);
            $this->assertSame($before, hash_file('sha256', public_path($photo->path)));
        }
        $this->assertSame([], glob(public_path('ai-gallery/gpt-delivery/*.jpg')));
    }

    public function test_traversal_and_out_of_gallery_symlink_return_original_without_creating_a_copy(): void
    {
        $sourcePhoto = $this->png('outside-source.png');
        rename(public_path($sourcePhoto->path), public_path('outside.png'));
        $before = hash_file('sha256', public_path('outside.png'));
        $this->assertTrue(symlink(public_path('outside.png'), public_path('ai-gallery/alias.png')));

        foreach (['ai-gallery/../outside.png', 'ai-gallery/alias.png', 'outside.png', 'ai-gallery/missing.png'] as $path) {
            $photo = $this->photo($path);
            $this->assertSame($photo->url(), app(DeliveryImage::class)->url($photo), $path);
        }

        $this->assertSame($before, hash_file('sha256', public_path('outside.png')));
        $this->assertDirectoryDoesNotExist(public_path('ai-gallery/gpt-delivery'));
    }

    public function test_invalid_and_truncated_sources_fall_back_without_modifying_the_source(): void
    {
        $valid = $this->png('valid.png');
        file_put_contents(public_path('ai-gallery/truncated.png'), substr(file_get_contents(public_path($valid->path)), 0, 33));
        file_put_contents(public_path('ai-gallery/html.png'), '<html>Це не зображення</html>');
        file_put_contents(public_path('ai-gallery/empty.png'), '');

        foreach (['truncated.png', 'html.png', 'empty.png'] as $name) {
            $photo = $this->photo('ai-gallery/'.$name);
            $before = hash_file('sha256', public_path($photo->path));
            $this->assertSame($photo->url(), app(DeliveryImage::class)->url($photo), $name);
            $this->assertSame($before, hash_file('sha256', public_path($photo->path)));
        }
        $this->assertSame([], glob(public_path('ai-gallery/gpt-delivery/*.jpg')));
        $this->assertSame([], glob(public_path('ai-gallery/gpt-delivery/.gpt-*')));
    }

    public function test_jpeg_exif_mirror_and_rotation_are_applied_before_resizing(): void
    {
        if (! function_exists('exif_read_data')) $this->markTestSkipped('Потрібне розширення EXIF.');
        foreach ([2 => [1600, 800], 6 => [800, 1600]] as $orientation => $dimensions) {
            $image = $this->geometry(2400, 1200, 0, 4);
            $red = imagecolorallocate($image, 240, 20, 20);
            imagefilledrectangle($image, 450, 200, 750, 400, $red);
            $relative = 'ai-gallery/orientation-'.$orientation.'.jpg';
            $source = public_path($relative);
            imagejpeg($image, $source, 100);
            imagedestroy($image);
            // Мінімальний APP1/TIFF містить лише Orientation, без сторонніх EXIF fixtures.
            $tiff = 'II'.pack('vV', 42, 8).pack('v', 1).pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\0\0".pack('V', 0);
            $exif = "Exif\0\0".$tiff;
            $jpeg = file_get_contents($source);
            file_put_contents($source, substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
            $this->assertSame($orientation, exif_read_data($source)['Orientation']);
            $before = hash_file('sha256', $source);
            $photo = $this->photo($relative);

            $url = app(DeliveryImage::class)->url($photo);

            $this->assertNotSame($photo->url(), $url);
            $output = imagecreatefromjpeg($this->deliveredPath($url));
            $this->assertSame($dimensions, [imagesx($output), imagesy($output)]);
            $pixel = imagecolorsforindex($output, imagecolorat($output, (int) (imagesx($output) * 0.75), (int) (imagesy($output) * 0.25)));
            $this->assertGreaterThan(200, $pixel['red']);
            $this->assertLessThan(70, $pixel['green']);
            $this->assertLessThan(70, $pixel['blue']);
            imagedestroy($output);
            $this->assertSame($before, hash_file('sha256', $source));
        }
    }

    private function photo(string $path): AiPhoto
    {
        $photo = new AiPhoto(['path' => $path]);
        $photo->purpose = 'product';

        return $photo;
    }

    private function png(string $name, int $width = 2400, int $height = 1200, int $seed = 0): AiPhoto
    {
        $image = $this->geometry($width, $height, $seed);
        // PNG без стиснення дає реальний великий файл із простими тестовими фігурами.
        imagepng($image, public_path('ai-gallery/'.$name), 0);
        imagedestroy($image);

        return $this->photo('ai-gallery/'.$name);
    }

    private function geometry(int $width, int $height, int $seed = 0, int $step = 80): \GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += $step) {
            for ($x = 0; $x < $width; $x += $step) {
                $color = imagecolorallocate($image, ($x * 3 + $y + $seed * 89) % 256, ($y * 5 + $x + $seed * 43) % 256, ($x + $y * 7 + $seed * 131) % 256);
                imagefilledrectangle($image, $x, $y, min($width - 1, $x + $step - 1), min($height - 1, $y + $step - 1), $color);
            }
        }

        return $image;
    }

    private function deliveredPath(string $url): string
    {
        $this->assertStringContainsString('/ai-gallery/gpt-delivery/', $url);
        $path = public_path(ltrim(parse_url($url, PHP_URL_PATH), '/'));
        $this->assertFileExists($path);

        return $path;
    }
}
