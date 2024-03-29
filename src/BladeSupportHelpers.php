<?php

use Anomaly\SearchModule\Item\Contract\ItemRepositoryInterface;
use Anomaly\SearchModule\Search\SearchCriteria;
use Anomaly\SettingsModule\Setting\Contract\SettingRepositoryInterface;
use Anomaly\Streams\Platform\Addon\AddonCollection;
use Anomaly\Streams\Platform\Entry\Contract\EntryInterface;
use Anomaly\Streams\Platform\Image\Image;
use Anomaly\Streams\Platform\Support\Str;
use Illuminate\Support\Facades\DB;

if (!function_exists('theme_path')) {
    function theme_path($path = ''): string
    {
        $addons = app(AddonCollection::class);
        $theme = $addons->themes->active('standard');
        return $theme->path . ($path ? DIRECTORY_SEPARATOR . $path : $path);
    }
}

if (!function_exists('inline_resource_content')) {
    function inline_resource_content($file): string
    {
        if (Str::startsWith($file, 'theme::') && $file = Str::replace('theme::', '', $file)) {
            $path = theme_path('resources/' . $file);
        } elseif (Str::startsWith($file, 'resource::') && $file = Str::replace('resource::', '', $file)) {
            $path = resource_path($file);
        } elseif (Str::startsWith($file, 'public::') && $file = Str::replace('public::', '', $file)) {
            $path = public_path($file);
        } else {
            $path = public_path($file);
        }

        try {
            return file_get_contents($path);
        } catch (\Exception $exception) {
            return '';
        }
    }
}

if (!function_exists('async_livewire_script')) {
    function async_livewire_script($options = []): string
    {
        $scripts = app(\Livewire\LivewireManager::class)->scripts($options);

        return $scripts;
    }
}

if (!function_exists('snake_to_kebab')) {
    function snake_to_kebab($string): string
    {
        return Str::kebab(Str::camel(Str::replace('/', '_', $string)));
    }
}

if (!function_exists('getImageFromEntry')) {
    function getImageFromEntry($entry, string $property, array $options = []): \Anomaly\FilesModule\File\FileModel|Image|null
    {
        $imageId = $entry->translate()->$property ?? $entry->translateOrDefault()->$property ?? $entry->$property;
        $imageId = $imageId ?? $entry->entry?->translate()->$property ?? $entry->entry?->translateOrDefault()->$property ?? $entry->entry?->$property;

        if (is_object($imageId)) {
            $imageId = $imageId->id;
        }
        if (!$imageId) {
            return null;
        }

        /** @var \Anomaly\FilesModule\File\FileModel $file */
        $file = \Anomaly\FilesModule\File\FileModel::find($imageId);
        if (in_array($file->getExtension(), config('anomaly.module.files::mimes.types.image'))) {
            /** @var Image $image */
            $image = app(Image::class);
            $image = $image->make($file);
            if (!$image) {
                return null;
            }
            $propertyData = $property . '_data';
            $imageData = $entry->translate()?->$propertyData ?? $entry->translateOrDefault()?->$propertyData ?? $entry->$propertyData;
            $imageData = $imageData ?? $entry->entry?->translate()?->$propertyData ?? $entry->entry?->translateOrDefault()?->$propertyData ?? $entry->entry?->$propertyData;
            $imageData = json_decode($imageData);
//            if (array_key_exists('testing', $options)) {
//                    dd($propertyData, $imageData, $image, $imageData->rotate);
//            }
            if ($imageData) {
                if (
                    property_exists($imageData, 'rotate')
                    && $imageData->rotate <> 0
                ) {
                    $image->rotate($imageData->rotate * -1);
                    $options['auto-rotate'] = null;
                }
                if (
                    property_exists($imageData, 'scaleX')
                    && $imageData->scaleX == -1
                ) {
                    $image->flip('h');
                    $options['auto-flip'] = null;
                }
                if (
                    property_exists($imageData, 'scaleY')
                    && $imageData->scaleY == -1
                ) {
                    $image->flip('v');
                    $options['auto-flip'] = null;
                }
                if (
                    property_exists($imageData, 'x')
                    && property_exists($imageData, 'y')
                    && property_exists($imageData, 'width')
                    && property_exists($imageData, 'height')
                ) {
                    $image->crop(round($imageData->width), round($imageData->height), round($imageData->x), round($imageData->y));
                    $options['auto-crop'] = null;
                }
                if (
                    property_exists($imageData, 'greyscale')
                    && $imageData->greyscale == 'Y'
                ) {
                    $image->greyscale();
                    $options['auto-greyscale'] = null;
                }
                $style = [];
                if (
                    property_exists($imageData, 'fit')
                    && $imageData->fit != ''
                ) {
                    $style['object-fit'] = $imageData->fit;
                }
                if (
                    property_exists($imageData, 'position')
                    && $imageData->position != ''
                ) {
                    $style['object-position'] = $imageData->position;
                }
                if (
                    property_exists($imageData, 'blend')
                    && $imageData->blend != ''
                ) {
                    $style['mix-blend-mode'] = $imageData->blend;
                }
                if ($style) {
                    $styleArray = [];
                    foreach ($style as $property => $value) {
                        $styleArray[] = $property . ': ' . $value;
                    }
                    $image->attr('style', join(';', $styleArray));
                }
                $image->attr('data-auto-generated', 'true');
            }
//            if (Str::contains($file->getName(), ' ')) {
//                dd($image);
//            }
            if ($image->getExtension() !== 'svg') {
                // TODO $image->encode overrides ALL other alternations
//                $image = $image->encode('webp', 75);
                foreach ($options as $method => $arguments) {
                    if (is_array($arguments)) {
                        $argumentsArray = $arguments;
                    } else {
                        $argumentsArray = explode(',', $arguments);
                    }
                    if ($method === 'cover' && in_array(count($argumentsArray), [1, 2])) {
                        $width = $argumentsArray[0];
                        $height = array_key_exists(1, $argumentsArray) ? $argumentsArray[1] : $argumentsArray[0];
                        $widthOrg = $width;
                        $heightOrg = $height;
                        $image->getWidth() > $image->getHeight() ? $width = null : $height = null;
                        $image->resize($width, $height, function ($constraint) {
                            $constraint->aspectRatio();
                        });
                        $image->resizeCanvas($widthOrg, $heightOrg, 'center', false, array(255, 255, 255, 0));
                    }
                    if (in_array($method = Str::camel($method), $image->getAllowedMethods())) {
                        call_user_func_array([$image, Str::camel($method)], $argumentsArray);
                    }
                }
            }
            if ($image->getExtension() !== 'svg' && count($image->getAlterations()) === 0 && request()->accepts(['image/webp'])) {
                $image = $image->encode('webp');
            }
            return $image;
        }
        return $file;
    }
}

if (!function_exists('getFileFromEntry')) {
    function getFileFromEntry($entry, string $property): \Anomaly\FilesModule\File\FileModel|Image|null
    {
        $fileId = $entry->translate()->$property ?? $entry->translateOrDefault()->$property ?? $entry->$property;

        if (is_object($fileId)) {
            $fileId = $fileId->id;
        }

        if (!$fileId) {
            return null;
        }

        /** @var \Anomaly\FilesModule\File\FileModel $file */
        $file = \Anomaly\FilesModule\File\FileModel::find($fileId);
        if (!$file) {
            return null;
        }
        return $file;
    }
}

if (!function_exists('getFullWidthSrcsetSizes')) {
    function getFullWidthSrcsetSizes(): array
    {
        return
            [
                "(max-width: 345px)" => [
                    "resize" => 345,
                    "quality" => 60
                ],
                "(max-width: 767px)" => [
                    "resize" => 768,
                    "quality" => 80
                ],
                "(max-width: 1023px)" => [
                    "resize" => 1024,
                    "quality" => 90
                ],
                "fallback" => [
                    "resize" => 1400,
                    "quality" => 90
                ],
            ];
    }
}

if (!function_exists('getHalfWidthSrcsetSizes')) {
    function getHalfWidthSrcsetSizes(): array
    {
        return [
            "(max-width: 345px)" => [
                "resize" => 173,
                "quality" => 60
            ],
            "(max-width: 767px)" => [
                "resize" => 384,
                "quality" => 80
            ],
            "(max-width: 1023px)" => [
                "resize" => 512,
                "quality" => 90
            ],
            "fallback" => [
                "resize" => 700,
                "quality" => 90
            ],
        ];
    }
}

if (!function_exists('getContainedWidthSrcsetSizes')) {
    function getContainedWidthSrcsetSizes(): array
    {
        return
            [
                "(max-width: 375px)" => [
                    "resize" => 375,
                    "quality" => 60
                ],
                "(max-width: 767px)" => [
                    "resize" => 668,
                    "quality" => 80
                ],
                "(max-width: 1023px)" => [
                    "resize" => 884,
                    "quality" => 90
                ],
                "fallback" => [
                    "resize" => 1200,
                    "quality" => 90
                ],
            ];
    }
}

if (!function_exists('getHalfContainedWidthSrcsetSizes')) {
    function getHalfContainedWidthSrcsetSizes(): array
    {
        return [
            "(max-width: 375px)" => [
                "resize" => 375 / 2,
                "quality" => 60
            ],
            "(max-width: 767px)" => [
                "resize" => 668 / 2,
                "quality" => 80
            ],
            "(max-width: 1023px)" => [
                "resize" => 884 / 2,
                "quality" => 90
            ],
            "fallback" => [
                "resize" => 1200 / 2,
                "quality" => 90
            ],
        ];
    }
}

if (!function_exists('fixFileUrl')) {
    /**
     * Get the correct path to the resource.
     */
    function fixFileUrl(string $url): string
    {
        if (Str::contains($url, '+')) {
            $url = Str::replace('+', ' ', $url);
        }
        return $url;
    }
}

if (!function_exists('localizePath')) {
    /**
     * Get the path to the resources folder.
     */
    function localizePath(string $path): string
    {
        $locale = config('app.locale');
        $settings = app(SettingRepositoryInterface::class);
        $defaultLocale = $settings->value('streams::default_locale');
        if ($defaultLocale !== $locale) {
            $path = '/' . $locale . $path;
        }

        return $path;
    }
}

if (!function_exists('textareaToHtml')) {
    function textareaToHtml($text)
    {
        if (is_a($text, \Anomaly\TextareaFieldType\TextareaFieldTypePresenter::class)) {
            return Str::replace("\n", '<br>', $text->value);
        }

        return $text;
    }
}

if (!function_exists('search')) {
    function search($search, $where, $options = []): Anomaly\SearchModule\Item\ItemCollection
    {
        /* @var ItemRepositoryInterface $repository */
        $repository = app(ItemRepositoryInterface::class);

        /* @var EntryInterface $model */
        $query = $repository->newQuery();
        $model = $repository->getModel();

        /**
         * Restrict the query to the active locale.
         */
        $query->where('locale', array_get($options, 'locale', config('app.locale')));

        $query->where(
            function ($query) use ($search, $options) {

                $threshold = array_get($options, 'threshold', 3);

                /**
                 * Remove symbols used by MySQL.
                 */
                $reservedSymbols = ['-', '+', '<', '>', '@', '(', ')', '~'];
                $term = str_replace($reservedSymbols, '', $search);

                $words = explode(' ', $term);

                foreach ($words as $key => $word) {

                    /*
                     * applying + operator (required word) only big words
                     * because smaller ones are not indexed by mysql
                     */
                    if (strlen($word) >= 3) {
                        $words[$key] = '+' . $word . '*';
                    }
                }

                /**
                 * Match the primary index fields.
                 * Title and description should trump
                 * anything else that get's matched.
                 */
                $match = app('db')->raw(
                    'MATCH (title,description) AGAINST ("' . implode(' ', $words) . '")'
                );

                $query->addSelect('*');
                $query->addSelect(DB::raw($match . ' AS _primary_score'));
                $query->where($match, '>=', $threshold);
                $query->orderBy($match, 'DESC');

                /**
                 * Match in the searchable data
                 * if possible. Expect lower scores.
                 */
                $match = app('db')->raw(
                    'MATCH (searchable) AGAINST ("' . implode(' ', $words) . '")'
                );

                $query->addSelect(DB::raw($match . ' AS _secondary_score'));
                $query->orWhere($match, '>=', $threshold);
                $query->orderBy($match, 'DESC');

                /**
                 * Match multiple words against
                 * the primary fields as well.
                 */
                if (count($words) > 1) {
                    foreach ($words as $k => $word) {

                        $match = app('db')->raw('MATCH (title,description) AGAINST ("' . $word . '")');

                        $query->addSelect(DB::raw($match . ' AS _sub_score_' . ($k + 1)));
                        $query->orWhere($match, '>=', $threshold);
                        $query->orderBy($match, 'DESC');
                    }
                }

                $query->orWhere('title', 'LIKE', '%' . $search . '%');
                $query->orWhere('description', 'LIKE', '%' . $search . '%');
                $query->orWhere('searchable', 'LIKE', '%' . $search . '%');
            }
        );

        return (new SearchCriteria($query, $model->getStream(), 'get'))->in($where)->get();
    }
}
