<?php

namespace App\Generators\Services;

use Illuminate\Http\UploadedFile;
use App\Generators\ImageUploadOption;
use Illuminate\Support\Facades\Storage;
use App\Generators\Interfaces\ImageServiceInterfaceV2;

class ImageServiceV2 implements ImageServiceInterfaceV2
{
    /**
     * Upload an image to disk and return the image name.
     */
    public function upload(
        string $name,
        string $path,
        ?string $defaultImage = null,
        string $disk = 'storage.public',
        int $width = 300,
        int $height = 300,
        bool $crop = true,
        bool $aspectRatio = true,
        bool $isCustomUpload = false
    ): ?string {
        $file = request()->file(key: $name);

        if (!$file || !$file->isValid()) {
            return $this->getActualImageName(name: $defaultImage, path: $path, disk: $disk);
        }

        if ($isCustomUpload) {
            // TODO: Write your custom image upload logic
            return $this->getActualImageName(name: $defaultImage, path: $path, disk: $disk);
        }

        $options = new ImageUploadOption(
            file: $file,
            path: "/$path/",
            defaultImage: $defaultImage,
            disk: $disk ?? config(key: 'generator.image.disk', default: 'storage.public'),
            width: $width ?? config(key: 'generator.image.width', default: 300),
            height: $height ?? config(key: 'generator.image.height', default: 300),
            crop: $crop,
            aspectRatio: $aspectRatio
        );

        return $this->handleFileUpload($options);
    }

    /**
     * Deletes an image from the specified disk.
     */
    public function delete(string $path, ?string $image, string $disk = 'storage.local'): bool
    {
        if (!$image) {
            return false;
        }

        $imageName = $this->getActualImageName(name: $image, path: $path, disk: $disk);
        $fullPath = "$path/$imageName";
        $actualDisk = $this->setDiskName($disk);

        return match ($actualDisk) {
            's3', 'public', 'local' => Storage::disk(name: $actualDisk)->delete(paths: $fullPath),
            default => @unlink(filename: public_path(path: $fullPath)),
        };
    }

    /**
     * Converts a disk name alias to its actual name.
     *
     * This method accepts a disk name alias and returns its actual name.
     * The supported aliases are 's3', 'storage.public' same as 'public' and
     * 'storage.local' same for 'local'. If no alias is provided, the method
     * defaults to 'public_path'.
     */
    public function setDiskName(string $disk): string
    {
        return match ($disk) {
            's3', 'local', 'public' => $disk,
            'storage.public' => 'public',
            'storage.local' => 'local',
            default => 'public_path',
        };
    }

    /**
     * Returns the default image URL to be used as a placeholder for non-existent
     * images. The default placeholder image is a 300x300 image with text
     * "No Image Available" from placehold.co.
     */
    public function getPlaceholderImage(): string
    {
        return config(key: 'generator.image.default', default: 'https://placehold.co/300?text=No+Image+Available');
    }

    /**
     * Determines if the specified disk is a private S3 disk.
     */
    public function isPrivateS3(string $disk): bool
    {
        return $disk === 's3' && config(key: 'filesystems.disks.s3.visibility') !== 'public';
    }

    /**
     * Generates a temporary URL to a file on the specified disk.
     *
     * The URL is valid for 5 minutes.
     */
    public function getTemporaryUrl(string $disk, string $path, string $value): string
    {
        return Storage::disk(name: $disk)->temporaryUrl(path: "$path/$value", expiration: now()->addMinutes(value: 5));
    }

    /**
     * Returns a publicly accessible URL to a file on the specified disk.
     *
     * This is the URL that will be used in the `<img>` tag to display the image.
     */
    public function getPublicUrl(string $disk, string $path, string $value): string
    {
        return Storage::disk(name: $disk)->url(path: "$path/$value");
    }

    /**
     * Returns a publicly accessible URL to a local file.
     *
     * This is the URL that will be used in the `<img>` tag to display the image.
     * The URL is generated using the Laravel `asset` helper.
     */
    public function getLocalAssetUrl(string $path, string $value): string
    {
        return asset(path: "$path/$value");
    }

    /**
     * Handles the file upload process with optional image manipulation.
     */
    private function handleFileUpload(ImageUploadOption $options): string
    {
        $filename = $this->generateFilename($options->file);
        $disk = $this->setDiskName($options->disk);

        if (!$this->isInterventionAvailable()) {
            return $this->handleWithoutIntervention(options: $options, filename: $filename, disk: $disk);
        }

        return $this->handleWithIntervention(options: $options, filename: $filename, disk: $disk);
    }

    /**
     * Handles the file upload process without using the Intervention Image library.
     *
     * This method stores the uploaded file directly to the specified disk and deletes any old image if present.
     */
    private function handleWithoutIntervention(ImageUploadOption $options, string $filename, string $disk): string
    {
        Storage::disk(name: $this->setDiskName($disk))->putFileAs(path: $options->path, file: $options->file, name: $filename);

        $this->deleteOldImage($options);

        return $filename;
    }

    /**
     * Handles the file upload process with optional image manipulation when the Intervention Image library is available.
     */
    private function handleWithIntervention(ImageUploadOption $options, string $filename, string $disk): string
    {
        $image = $this->processWithInterventionImage($options);

        Storage::disk($this->setDiskName($disk))->put(path: "$options->path/$filename", contents: (string) $image);

        $this->deleteOldImage($options);

        return $filename;
    }

    /**
     * Deletes the old image if a default image is specified in the options.
     *
     * This method checks for the presence of a default image in the provided
     * options array and deletes it from the specified disk and path if found.
     */
    private function deleteOldImage(ImageUploadOption $options): bool
    {
        return $options->defaultImage ? $this->delete($options->path, $options->defaultImage, $options->disk) : false;
    }

    /**
     * Generates a random filename for an uploaded image file.
     *
     * If the Intervention Image library is available, a random string will be
     * generated and appended with the '.webp' extension. Otherwise, the
     * {@link Illuminate\Http\UploadedFile::hashName()} method will be used.
     */
    private function generateFilename(UploadedFile $file): string
    {
        return $this->isInterventionAvailable() ? str()->random(30) . '.webp' : $file->hashName();
    }

    /**
     * Processes an image using the Intervention Image library.
     *
     * This method reads an image file and encodes it to the WebP format with
     * a specified quality. It optionally crops and resizes the image based on
     * the provided options.
     */
    private function processWithInterventionImage(ImageUploadOption $options): \Intervention\Image\Interfaces\EncodedImageInterface
    {
        $image = \Intervention\Image\Laravel\Facades\Image::read($options->file);
        $encode = new \Intervention\Image\Encoders\WebpEncoder(65);

        return $options->crop
            ? ($options->aspectRatio
            ? $image->scaleDown($options->width, $options->height)->encode($encode)
            : $image->resizeDown($options->width, $options->height)->encode($encode))
            : $image->encode($encode);
    }

    /**
     * Checks if the Intervention Image library is available.
     *
     * This method verifies the existence of the Intervention Image library
     * by checking if the corresponding class is available. It is used to
     * determine if image manipulation capabilities are supported.
     */
    private function isInterventionAvailable(): bool
    {
        return class_exists(class: \Intervention\Image\Laravel\Facades\Image::class);
    }

    /**
     * Retrieves the actual image name if it exists on the specified disk.
     *
     * This method extracts the image name from the given path, cleans up any query
     * parameters, and checks if the image exists on the specified disk. If the image
     * does not exist or if no name is provided, it returns null.
     */
    protected function getActualImageName(string $name = null, string $path, string $disk = 'public'): ?string
    {
        if (!$name) {
            return null;
        }

        $image = last(array: explode(separator: '/', string: $name));
        $cleanImg = str_contains(haystack: $image, needle: '?expires') ? str(string: $image)->before(search: '?expires')->toString() : $image;
        $fullPath = "$path/$cleanImg";
        $actualDisk = $this->setDiskName($disk);

        $exists = match ($actualDisk) {
            's3', 'local', 'public' => Storage::disk(name: $actualDisk)->exists(path: $fullPath),
            default => file_exists(filename: public_path($fullPath)),
        };

        if (!$exists) {
            return null;
        }

        return $cleanImg;
    }
}