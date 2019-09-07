<?php

namespace Plank\Mediable;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Plank\Mediable\Helpers\File;

/**
 * Media Model.
 * @property int|string $id
 * @property string $disk
 * @property string $directory
 * @property string $filename
 * @property string $extension
 * @property string $basename
 * @property string $mime_type
 * @property string $aggregate_type
 * @property int $size
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Pivot $pivot
 * @method static Builder inDirectory(string $disk, string $directory, bool $recursive = false)
 * @method static Builder inOrUnderDirectory(string $disk, string $directory)
 * @method static Builder whereBasename(string $basename)
 * @method static Builder forPathOnDisk(string $disk, string $path)
 * @method static Builder unordered()
 */
class Media extends Model
{
    const TYPE_IMAGE = 'image';
    const TYPE_IMAGE_VECTOR = 'vector';
    const TYPE_PDF = 'pdf';
    const TYPE_VIDEO = 'video';
    const TYPE_AUDIO = 'audio';
    const TYPE_ARCHIVE = 'archive';
    const TYPE_DOCUMENT = 'document';
    const TYPE_SPREADSHEET = 'spreadsheet';
    const TYPE_PRESENTATION = 'presentation';
    const TYPE_OTHER = 'other';
    const TYPE_ALL = 'all';

    protected $guarded = ['id', 'disk', 'directory', 'filename', 'extension', 'size', 'mime_type', 'aggregate_type'];

    /**
     * {@inheritdoc}
     */
    public static function boot()
    {
        parent::boot();

        //remove file on deletion
        static::deleted(function (Media $media) {
            $media->handleMediaDeletion();
        });
    }

    /**
     * Retrieve all associated models of given class.
     * @param  string $class FQCN
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function models(string $class)
    {
        return $this
            ->morphedByMany(
                $class,
                'mediable',
                config('mediable.mediables_table', 'mediables')
            )
            ->withPivot('tag', 'order');
    }

    /**
     * Retrieve the file extension.
     * @return string
     */
    public function getBasenameAttribute()
    {
        return $this->filename . '.' . $this->extension;
    }

    /**
     * Query scope for to find media in a particular directory.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  string $disk Filesystem disk to search in
     * @param  string $directory Path relative to disk
     * @param  bool $recursive (_optional_) If true, will find media in or under the specified directory
     * @return void
     */
    public function scopeInDirectory(Builder $q, string $disk, string $directory, bool $recursive = false)
    {
        $q->where('disk', $disk);
        if ($recursive) {
            $directory = str_replace(['%', '_'], ['\%', '\_'], $directory);
            $q->where('directory', 'like', $directory . '%');
        } else {
            $q->where('directory', '=', $directory);
        }
    }

    /**
     * Query scope for finding media in a particular directory or one of its subdirectories.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  string $disk Filesystem disk to search in
     * @param  string $directory Path relative to disk
     * @return void
     */
    public function scopeInOrUnderDirectory(Builder $q, string $disk, string $directory)
    {
        $q->inDirectory($disk, $directory, true);
    }

    /**
     * Query scope for finding media by basename.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  string $basename filename and extension
     * @return void
     */
    public function scopeWhereBasename(Builder $q, string $basename)
    {
        $q->where('filename', pathinfo($basename, PATHINFO_FILENAME))
            ->where('extension', pathinfo($basename, PATHINFO_EXTENSION));
    }

    /**
     * Query scope finding media at a path relative to a disk.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @param  string $disk
     * @param  string $path directory, filename and extension
     * @return void
     */
    public function scopeForPathOnDisk(Builder $q, string $disk, string $path)
    {
        $q->where('disk', $disk)
            ->where('directory', File::cleanDirname($path))
            ->where('filename', pathinfo($path, PATHINFO_FILENAME))
            ->where('extension', pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * Query scope to remove the order by clause from the query.
     * @param  \Illuminate\Database\Eloquent\Builder $q
     * @return void
     */
    public function scopeUnordered(Builder $q)
    {
        $query = $q->getQuery();
        if ($query->orders) {
            $query->orders = null;
        }
    }

    /**
     * Calculate the file size in human readable byte notation.
     * @param  int $precision (_optional_) Number of decimal places to include.
     * @return string
     */
    public function readableSize(int $precision = 1)
    {
        return File::readableSize($this->size, $precision);
    }

    /**
     * Get the path to the file relative to the root of the disk.
     * @return string
     */
    public function getDiskPath()
    {
        return ltrim(rtrim($this->directory, '/') . '/' . ltrim($this->basename, '/'), '/');
    }

    /**
     * Get the absolute filesystem path to the file.
     * @return string
     */
    public function getAbsolutePath()
    {
        return $this->getUrlGenerator()->getAbsolutePath();
    }

    /**
     * Check if the file is located below the public webroot.
     * @return bool
     */
    public function isPubliclyAccessible()
    {
        return $this->getUrlGenerator()->isPubliclyAccessible();
    }

    /**
     * Get the absolute URL to the media file.
     * @throws \Plank\Mediable\Exceptions\MediaUrlException If media's disk is not publicly accessible
     * @return string
     */
    public function getUrl()
    {
        return $this->getUrlGenerator()->getUrl();
    }

    /**
     * Check if the file exists on disk.
     * @return bool
     */
    public function fileExists()
    {
        return $this->storage()->has($this->getDiskPath());
    }

    /**
     * [isVisible description]
     * @return boolean [description]
     */
    public function isVisible()
    {
        return $this->storage()->getVisibility($this->getDiskPath()) === 'public';
    }

    public function makePrivate()
    {
        $this->storage()->setVisibility($this->getDiskPath(), 'private');
    }

    public function makePublic()
    {
        $this->storage()->setVisibility($this->getDiskPath(), 'public');
    }

    /**
     * Retrieve the contents of the file.
     * @return string
     */
    public function contents()
    {
        return $this->storage()->get($this->getDiskPath());
    }

    /**
     * Move the file to a new location on disk.
     *
     * Will invoke the `save()` method on the model after the associated file has been moved to prevent synchronization errors
     * @param  string $destination directory relative to disk root
     * @param  string $filename filename. Do not include extension
     * @return void
     */
    public function move(string $destination, string $filename = null)
    {
        app('mediable.mover')->move($this, $destination, $filename);
    }

    /**
     * Copy the file from one Media object to another one.
     *
     * Will invoke the `save()` method on the model after the associated file has been copied to prevent synchronization errors
     * @param  string $destination directory relative to disk root
     * @param  string $filename optional filename. Do not include extension
     * @return \Plank\Mediable\Media
     */
    public function copyTo($destination, $filename = null)
    {
        return app('mediable.mover')->copyTo($this, $destination, $filename);
    }

    /**
     * Rename the file in place.
     * @param  string $filename
     * @return void
     * @see \Plank\Mediable\Media::move()
     */
    public function rename(string $filename)
    {
        $this->move($this->directory, $filename);
    }

    protected function handleMediaDeletion()
    {
        // optionally detach mediable relationships on soft delete
        if (static::hasGlobalScope(SoftDeletingScope::class) && !$this->forceDeleting) {
            if (config('mediable.detach_on_soft_delete')) {
                $this->newBaseQueryBuilder()
                    ->from(config('mediable.mediables_table', 'mediables'))
                    ->where('media_id', $this->getKey())
                    ->delete();
            }
            // unlink associated file on delete
        } elseif ($this->storage()->has($this->getDiskPath())) {
            $this->storage()->delete($this->getDiskPath());
        }
    }

    /**
     * Get the filesystem object for this media.
     * @return Filesystem
     */
    protected function storage()
    {
        return app('filesystem')->disk($this->disk);
    }

    /**
     * Get a UrlGenerator instance for the media.
     * @return \Plank\Mediable\UrlGenerators\UrlGeneratorInterface
     */
    protected function getUrlGenerator()
    {
        return app('mediable.url.factory')->create($this);
    }
}
