<?php

namespace EvdigiIna\Generator\Generators;

class ModelGenerator
{
    /**
     * Generate a model file.
     */
    public function generate(array $request): void
    {
        $path = GeneratorUtils::getModelLocation($request['model']);
        $model = GeneratorUtils::setModelName(model: $request['model'], style: 'default');
        $modelNameSingularPascalCase = GeneratorUtils::singularPascalCase($model);

        $fields = "[";
        $casts = "[";
        $relations = "";
        $totalFields = count($request['fields']);
        $dateTimeFormat = config('generator.format.datetime') ?? 'Y-m-d H:i:s';
        $protectedHidden = "";
        $castImages = "";
        $uploadPaths = "";

        if (in_array(needle: 'password', haystack: $request['input_types'])) {
            $protectedHidden .= <<<PHP
            /**
                 * The attributes that should be hidden for serialization.
                 *
                 * @var string[]
                */
                protected \$hidden = [
            PHP;
        }

        $namespace = !$path ? "namespace App\\Models;" : "namespace App\\Models\\$path;";

        foreach ($request['fields'] as $i => $field) {
            $fields .= match ($i + 1 != $totalFields) {
                true => "'" . str()->snake($field) . "', ",
                default => "'" . str()->snake($field) . "']",
            };

            if ($request['input_types'][$i] == 'password') {
                $protectedHidden .= "'" . str()->snake($field) . "', ";
            }

            switch ($request['column_types'][$i]) {
                case 'date':
                    if ($request['input_types'][$i] != 'month') {
                        $dateFormat = config('generator.format.date') ?? 'd/m/Y';
                        $casts .= "'" . str()->snake($field) . "' => 'date:$dateFormat', ";
                    }
                    break;
                case 'time':
                    $timeFormat = config('generator.format.time') ? config('generator.format.time') : 'H:i';
                    $casts .= "'" . str()->snake($field) . "' => 'datetime:$timeFormat', ";
                    break;
                case 'year':
                    $casts .= "'" . str()->snake($field) . "' => 'integer', ";
                    break;
                case 'dateTime':
                    $casts .= "'" . str()->snake($field) . "' => 'datetime:$dateTimeFormat', ";
                    break;
                case 'float':
                    $casts .= "'" . str()->snake($field) . "' => 'float', ";
                    break;
                case 'boolean':
                    $casts .= "'" . str()->snake($field) . "' => 'boolean', ";
                    break;
                case 'double':
                    $casts .= "'" . str()->snake($field) . "' => 'double', ";
                    break;
                case 'foreignId':
                    $constrainPath = GeneratorUtils::getModelLocation($request['constrains'][$i]);
                    $constrainName = GeneratorUtils::setModelName($request['constrains'][$i]);

                    $foreign_id = isset($request['foreign_ids'][$i]) ? ", '" . $request['foreign_ids'][$i] . "'" : '';

                    if ($i > 0)
                        $relations .= "\t";

                    /**
                     * will generate something like:
                     * \App\Models\Main\Product::class
                     *              or
                     *  \App\Models\Product::class
                     */
                    $constrainPath = match ($constrainPath) {
                        '' => "\\App\\Models\\$constrainName",
                        default => "\\App\\Models\\$constrainPath\\$constrainName",
                    };

                    /**
                     * will generate something like:
                     *
                     * public function product()
                     * {
                     *     return $this->belongsTo(\App\Models\Main\Product::class);
                     *                              or
                     *     return $this->belongsTo(\App\Models\Product::class);
                     * }
                     */
                    $relations .= "\n\tpublic function " . str()->snake($constrainName) . "(): \Illuminate\Database\Eloquent\Relations\BelongsTo\n\t{\n\t\treturn \$this->belongsTo(" . $constrainPath . "::class" . $foreign_id . ");\n\t}";
                    break;
            }

            switch ($request['input_types'][$i]) {
                case 'month':
                    $castFormat = config('generator.format.month') ? config('generator.format.month') : 'm/Y';
                    $casts .= "'" . str()->snake($field) . "' => 'date:$castFormat', ";
                    break;
                case 'week':
                    $casts .= "'" . str()->snake($field) . "' => 'date:Y-\WW', ";
                    break;
                case 'file':
                    // $uploadPaths .= "public string $" . GeneratorUtils::singularCamelCase($request['fields'][$i]) . "Path = '" . GeneratorUtils::pluralKebabCase($request['fields'][$i]) . "', ";

                    $setReturnComment = $this->setReturnComment(config(key: 'generator.image.disk', default: 'storage.public'));

                    $castImages .= GeneratorUtils::replaceStub(replaces: [
                        'fieldCamelCase' => str($field)->camel()->toString(),
                        'path' => GeneratorUtils::pluralKebabCase($field),
                        'disk' => config(key: 'generator.image.disk', default: 'storage.local'),
                        'defaultImage' => config(key: 'generator.image.default', default: 'https://via.placeholder.com/350?text=No+Image+Avaiable'),
                        'returnPublicPath' => $setReturnComment['public_path'],
                        'returnStoragePublicS3' => $setReturnComment['storage_public_s3'],
                        'returnStorageLocal' => $setReturnComment['storage_local'],
                    ], stubName: 'model-cast') . "\t";
                    break;
            }

            // integer/bigInteger/tinyInteger/
            if (str_contains(haystack: $request['column_types'][$i], needle: 'integer')) {
                $casts .= "'" . str()->snake($field) . "' => 'integer', ";
            }

            if (in_array(needle: $request['column_types'][$i], haystack: ['string', 'text', 'char']) && $request['input_types'][$i] != 'week' && $request['input_types'][$i] != 'file') {
                $casts .= "'" . str()->snake($field) . "' => 'string', ";
            }
        }

        if ($protectedHidden != "") {
            // remove "', " and then change to "'" in the of array for better code.
            // $protectedHidden  = str_replace("', ", "'", $protectedHidden);
            $protectedHidden = substr(string: $protectedHidden, offset: 0, length: -2) . "];";
        }

        $casts .= <<<PHP
        'created_at' => 'datetime:$dateTimeFormat', 'updated_at' => 'datetime:$dateTimeFormat'
        PHP;

        $casts .= "]";

        // $constructFunc = GeneratorUtils::replaceStub(replaces: [
        //     'uploadPaths' => $uploadPaths,
        //     'disk' => config(key: 'generator.image.disk', default: 'storage.local'),
        // ], stubName: 'models/construct-function');

        $template = GeneratorUtils::replaceStub(replaces: [
            'modelName' => $modelNameSingularPascalCase,
            'fields' => $fields,
            'casts' => $casts,
            'relations' => $relations,
            'namespace' => $namespace,
            'protectedHidden' => $protectedHidden,
            'pluralSnakeCase' => GeneratorUtils::pluralSnakeCase($model),
            'castImages' => $castImages,
            'importCastImage' => "use Illuminate\Support\Facades\Storage;\nuse Illuminate\Database\Eloquent\Casts\Attribute;\nuse App\Generators\Services\ImageServiceV2;\n",
            // 'constructFunc' => $constructFunc
        ], stubName: 'model');

        if (!$path) {
            file_put_contents(filename: app_path(path: "/Models/$modelNameSingularPascalCase.php"), data: $template);
        } else {
            $fullPath = app_path("/Models/$path");
            GeneratorUtils::checkFolder($fullPath);
            file_put_contents(filename: "$fullPath/$modelNameSingularPascalCase.php", data: $template);
        }
    }

    public function setReturnComment(string $disk): array
    {
        switch ($disk) {
            case 'storage.public':
            case 'storage':
            case 's3':
                return [
                    'public_path' => '//',
                    'storage_public_s3' => '',
                    'storage_local' => '//',
                ];
            case 'storage.local':
                return [
                    'public_path' => '//',
                    'storage_public_s3' => '//',
                    'storage_local' => '',
                ];
            default:
                return [
                    'public_path' => '',
                    'storage_public_s3' => '//',
                    'storage_local' => '//',
                ];
        }
    }
}
