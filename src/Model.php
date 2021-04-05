<?php

declare (strict_types=1);

namespace Generate;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\db\PDOConnection;
use think\db\Query;
use think\helper\Str;
use think\Model as ThinkModel;
use think\model\Collection;
use think\model\Relation;
use think\model\relation\BelongsTo;
use think\model\relation\BelongsToMany;
use think\model\relation\HasMany;
use think\model\relation\HasManyThrough;
use think\model\relation\HasOne;
use think\model\relation\HasOneThrough;
use think\model\relation\MorphMany;
use think\model\relation\MorphOne;
use think\model\relation\MorphTo;
use think\model\relation\MorphToMany;

/**
 * Class Model
 * 自动生成模型的类property和schema
 * 指令:   php think model app\\model\\system\\CjwtModel,LogModel -D app/model,app/model/system
 * model: 模型名称(默认查找app/model/下的模型文件)或模型命名空间名称(以\\表示层级关系),支持多模型【,】分割
 * -D:    目录名称(以/表示层级关系),支持多目录【,】分割
 * php think model -h 查看更多指令信息
 * @package app\command
 * @author  CQR 2020/5/10 13:14
 */
class Model extends Command
{
    /**
     * 处理的模型数据
     * @var array
     */
    private $models = [];

    /**
     * 模型实例
     * @var ThinkModel
     */
    private $model;

    /**
     * 父级的模型名
     * @var string
     */
    private $parentModelName = 'BaseModel';

    /**
     * query实例
     * @var Query
     */
    private $query;

    /**
     * 反射实例
     * @var ReflectionClass
     */
    private $reflection;

    /**
     * 4格缩进
     * @var string
     */
    private $indent = '    ';

    /**
     * @var array
     */
    private $table;

    /**
     * 用于搜索的开始符号
     * @var string
     */
    private $schemaStart = 'protected $schema = [';

    /**
     * 用于搜索的结束符号
     * @var string
     */
    private $schemaEnd = '];';

    /**
     * 需要写入的schema
     * @var array
     */
    private $schema = [];

    /**
     * 类schema注释
     * @var array
     */
    private $schemaDoc = [];

    /**
     * 默认命名空间
     * @var string
     */
    private $defaultNamespace = 'app\\model\\';

    /**
     * 获取到的文件内容
     * @var array
     */
    private $content = [];

    /**
     * 数据库字段
     * @var array
     */
    private $databaseField = [];

    /**
     * 类property注释
     * @var array
     */
    private $property = [];

    /**
     * 字段是否大写
     * @var bool
     */
    private $propertyUpperCase = true;

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('schema')
            ->addArgument('model', Argument::OPTIONAL | Argument::IS_ARRAY, 'Which models to include,Example:UserModel or app\\model\\UserModel,Multiple Example:UserModel app\\model\\UserModel', [])
            ->addOption('dir', 'D|d', Option::VALUE_OPTIONAL | Option::VALUE_IS_ARRAY, 'the model directory,Example:-D app/model,Multiple Example:-D app/model -D app/model/system', [])
            ->setDescription('generate model schema and property');
    }

    /**
     * @inheritDoc
     */
    protected function execute(Input $input, Output $output): ?int
    {
        $this->loadModels();
        if (!$this->models) {
            $this->error('model undefined');
        }
        foreach ($this->models as $class => $value) {
            $this->output->info("Loading model {$class}");
            if (!class_exists($class)) {
                $this->output->warning("{$class} is not exists continue");
                continue;
            }
            if (!$this->ReflectionClass($class)) {
                continue;
            }
            $this->setTable();                                   //设置table
            $this->setDatabaseField();                           //设置数据库字段信息
            $this->setSchema();                                  //设置schema
            $this->setContentToArray($value['filePath']);        //文件内容转成数组
            $this->setProperty();                                //设置property
            $this->appendTableToContent($value['className']);    //追加schema到content
            $this->appendSchemaToContent();                      //追加schema到content
            $this->appendPropertyToContent($value['className']); //追加property到content
            $this->write($value['filePath']);                    //写入文件
            $this->reset();                                      //重置类变量

        }
        $this->info('completed');

        return null;
    }

    /**
     * 反射
     * @param string $class
     * @throws \ReflectionException
     * @return bool
     * @author CQR 2020/5/10 14:19
     */
    private function ReflectionClass(string $class): bool
    {
        $this->reflection = new ReflectionClass($class);
        if (!$this->reflection->isSubclassOf(ThinkModel::class)) {
            $this->output->warning("{$class} is not Model Subclass continue");

            return false;
        }
        if (!$this->reflection->isInstantiable()) {
            $this->output->warning("{$class} is abstract continue");

            return false;
        }
        $this->model = new $class();
        $this->query = $this->model->db();

        return true;
    }

    /**
     * 写入schema
     * @author CQR 2020/5/10 13:36
     */
    private function appendSchemaToContent(): void
    {
        $hasSchema = $this->searchDown($this->schemaStart);
        if ($hasSchema) {
            //已存在schema
            $rowNum = $this->searchUp('/**', $hasSchema);
            $i      = 0;
            foreach ($this->content as $key => $val) {
                if ($key > $hasSchema) {
                    if (mb_strpos($val, $this->schemaEnd) !== false) {
                        break;
                    }
                    //删除代码
                    unset($this->content[$key]);
                    continue;
                }
                if ($key >= $rowNum && $key < $hasSchema) {
                    $i++;
                    //删除注释
                    unset($this->content[$key]);
                }
            }
            //追加代码
            $this->spliceArrayByOffset($this->content, $this->schema, $hasSchema - $i + 1);
            array_unshift($this->schemaDoc, $this->indent . '/**', $this->indent . ' * @var string[]');
            $this->schemaDoc[] = $this->indent . ' */';
            //追加注释
            $this->spliceArrayByOffset($this->content, $this->schemaDoc, $hasSchema - $i);
        } else {
            //不存在schema
            $schema[] = $this->indent . '/**';
            $schema[] = $this->indent . ' * @var string[]';
            $this->spliceArrayByOffset($schema, $this->schemaDoc, 3);
            $schema[] = $this->indent . ' */';
            $schema[] = $this->indent . $this->schemaStart;
            $this->spliceArrayByOffset($schema, $this->schema, count($this->schemaDoc) + 4);
            $schema[] = $this->indent . $this->schemaEnd;
            $schema[] = '';
            $this->spliceArrayByOffset($this->content, $schema, $this->searchDown('protected $table') + 2);
        }
    }

    /**
     * 属性注释
     * @param string $className
     * @author CQR 2021/2/4 22:51
     */
    private function appendPropertyToContent(string $className): void
    {
        $this->formatProperty();
        $classRow    = "class {$className} extends $this->parentModelName";
        $classRowNum = array_search($classRow, $this->content);
        $hasProperty = $this->searchUp('/**', $classRowNum);
        if ($hasProperty !== false) {
            //类中存在类注释
            $i = 1;
            foreach ($this->content as $key => $val) {
                if (mb_strpos($val, $classRow) !== false) {
                    break;
                }
                //过滤泛型字段
                if (strpos($val, '@property T') !== false) {
                    $i++;
                    continue;
                }
                //过滤虚拟字段
                if (strpos($val, '虚拟字段') !== false) {
                    $i++;
                    continue;
                }
                //过滤旧注释
                if (strpos($val, '@property') !== false) {
                    $i++;
                    unset($this->content[$key]);
                }
            }
            $this->spliceArrayByOffset($this->content, $this->property, $classRowNum - $i);
        } else {
            //类中不存在类注释
            $property[] = '/**';
            $property[] = " * {$className}";
            $property[] = ' */';
            $this->spliceArrayByOffset($property, $this->property, 2);
            $this->spliceArrayByOffset($this->content, $property, $classRowNum);
        }
    }

    /**
     * 获取数据库字段信息
     * @author CQR 2021/2/5 22:42
     */
    private function setDatabaseField(): void
    {
        try {
            $this->databaseField = $this->query->getFields();
            /** @var PDOConnection $connect */
            $connect = $this->query->getConnection();
            $type    = $connect->getTableFieldsInfo($this->query->getTable());
            foreach ($this->databaseField as $key => $value) {
                $this->databaseField[$key]['type'] = $type[$key] ?? '';
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 生成写入属性并且格式化
     * @return void
     * @author CQR 2020/5/10 13:32
     */
    private function setSchema(): void
    {
        [$maxKey, $maxVal] = $this->getMaxKeyValLength(array_column($this->databaseField, 'type', 'name'));
        foreach ($this->databaseField as $key => &$val) {
            $spaceKey       = $this->spaceDiff($key, $maxKey);
            $spaceVal       = $this->spaceDiff($val['type'], $maxVal);
            $val['comment'] = $val['comment'] ? str_replace(["\r\n", "\r", "\n"], '', $val['comment']) : $key;
            $this->schema[] = "$this->indent$this->indent'$key'$spaceKey => '{$val['type']}', $spaceVal// {$val['comment']}";
        }
    }

    /**
     * 加载模型数据
     * @author CQR 2020/5/10 14:47
     */
    private function loadModels(): void
    {
        if (!$this->models) {
            $models = $this->input->getArgument('model');
            $dirs   = $this->input->getOption('dir');
            if (!$models && !$dirs) {
                $this->confirmModel();
            } else {
                $this->parseModel($models);
                $this->parseDir($dirs);
            }
        }
    }

    /**
     * 确认输入模型
     * @author CQR 2021/2/5 19:38
     */
    private function confirmModel(): void
    {
        $model = $this->output->ask($this->input, '请输入模型名称或命名空间，示例：（UserModel或app\\model\\system\\UserModel）');
        $this->parseModel([$model]);
        if (!$this->models) {
            $this->error("{$model} is not exists");
        }

        $this->execute($this->input, $this->output);
    }

    /**
     * 解析模型文件信息
     * @param array $args
     * @author CQR 2020/7/16 22:50
     */
    private function parseModel(array $args): void
    {
        foreach ($args as $arg) {
            if (strpos($arg, ',') !== false) {
                $explode = explode(',', $arg);
                $this->parseModel($explode);
            }
            if (strpos($arg, '\\') !== false) {
                $replace  = str_replace('\\', DIRECTORY_SEPARATOR, $arg);
                $filePath = $this->app->getRootPath() . $replace . '.php';
            } else {
                $arg = ucwords($arg);
                $this->parseModel([$this->defaultNamespace . $arg]);
                continue;
            }

            if (file_exists($filePath)) {
                $this->models[$arg] = [
                    'className' => str_replace('.php', '', basename($filePath)),
                    'filePath'  => $filePath,
                ];
            }
        }
    }

    /**
     * 解析目录信息
     * @param array $dirs
     * @author CQR 2020/7/16 22:54
     */
    private function parseDir(array $dirs): void
    {
        foreach ($dirs as $dir) {
            if (strpos($dir, ',') !== false) {
                $explode = explode(',', $dir);
                $this->parseDir($explode);
            }
            $modelDir = $this->app->getRootPath() . $dir;
            if (!file_exists($modelDir)) {
                continue;
            }
            foreach (scandir($modelDir) as $file) {
                if (in_array($file, ['.', '..'])) {
                    continue;
                }
                $filePath = $modelDir . DIRECTORY_SEPARATOR . $file;
                if (is_dir($filePath)) {
                    $this->parseDir([$dir . DIRECTORY_SEPARATOR . $file]);
                } else {
                    $className            = str_replace('.php', '', $file);
                    $class                = str_replace(DIRECTORY_SEPARATOR, '\\', $dir) . '\\' . $className;
                    $this->models[$class] = [
                        'className' => $className,
                        'filePath'  => $filePath
                    ];
                }
            }
        }
    }

    /**
     * 获取数组中最大的长度
     * @param array $field
     * @return array
     * @author CQR 2020/5/10 15:46
     */
    private function getMaxKeyValLength(array $field): array
    {
        $maxKey = 0;
        $maxVal = 0;
        foreach ($field as $key => $value) {
            $lenKey = strlen($key);
            $lenVal = strlen($value);
            if ($maxKey < $lenKey) {
                $maxKey = $lenKey;
            }
            if ($maxVal < $lenVal) {
                $maxVal = $lenVal;
            }
        }

        return [$maxKey, $maxVal];
    }

    /**
     * 计算相差的空格数，用于格式化代码
     * @param string $value
     * @param int    $max
     * @return string
     * @author CQR 2020/5/10 15:48
     */
    private function spaceDiff(string $value, int $max): string
    {
        $space = '';
        for ($i = 1; $i <= $max - strlen($value); $i++) {
            $space .= ' ';
        }

        return $space;
    }

    /**
     * 格式化property
     * @author CQR 2021/2/6 9:46
     */
    private function formatProperty(): void
    {
        foreach ($this->property as $key => $value) {
            $this->property[$key]['type'] = implode('|', array_filter(array_unique($value['type'])));
        }
        [$maxKey, $maxVal] = $this->getMaxKeyValLength(array_column($this->property, 'type', 'name'));
        foreach ($this->property as $key => $val) {
            $spaceKey             = $this->spaceDiff($key, $maxKey);
            $spaceVal             = $this->spaceDiff($val['type'], $maxVal);
            $this->property[$key] = " * @property {$val['type']}{$spaceVal} $" . $key . "  {$spaceKey}{$val['comment']}";
        }
    }

    /**
     * 追加进property
     * @param string      $name    名称
     * @param string      $type    类型
     * @param string|null $comment 说明
     * @author CQR 2021/2/5 17:13
     */
    private function addProperty(string $name, string $type, ?string $comment = null): void
    {
        if ($this->propertyUpperCase) {
            //转大写
            $name = strtoupper($name);
        } else {
            //驼峰转下划线
            $name = Str::snake($name);
        }
        $this->property[$name]['name']   = $name;
        $this->property[$name]['type'][] = in_array($type, ['date', 'datetime']) ? 'string' : $type;
        //已经有注释的不覆盖了
        if (empty($this->property[$name]['comment'])) {
            $this->property[$name]['comment'] = $comment ?: $name;
        }
    }

    /**
     * 追加进Schema
     * @param string $value
     * @author CQR 2021/2/5 17:13
     */
    private function addSchemaDoc(string $value): void
    {
        $this->schemaDoc[] = $this->indent . ' * @uses ' . $value;
    }

    /**
     * 设置property
     * @author CQR 2021/2/5 17:14
     */
    private function setProperty(): void
    {
        foreach ($this->databaseField as $value) {
            $this->addProperty($value['name'], $value['type'], $value['comment']);
        }
        foreach ($this->reflection->getMethods() as $method) {
            //只获取本类的函数（排除继承的函数）
            if ($method->getDeclaringClass()->getName() === $this->reflection->getName()) {
                $methodName = $method->getName();
                if ('getAttr' !== $methodName && Str::startsWith($methodName, 'get') && Str::endsWith($methodName, 'Attr')) {
                    //获取器
                    $this->addSchemaDoc($methodName);
                    $this->addProperty(substr($methodName, 3, -4), $this->getReturnType($method), $this->getMethodComment($method->getDocComment()) . '[获取器]');
                } elseif ('setAttr' !== $methodName && Str::startsWith($methodName, 'set') && Str::endsWith($methodName, 'Attr')) {
                    //修改器
                    $this->addSchemaDoc($methodName);
                } elseif (Str::startsWith($methodName, 'search') && Str::endsWith($methodName, 'Attr')) {
                    //搜索器
                    $this->addSchemaDoc($methodName);
                } elseif ($method->isPublic() && $method->getNumberOfRequiredParameters() === 0) {
                    //模型关联
                    try {
                        $return = $method->invoke($this->model);
                        if ($return instanceof Relation) {
                            $comment = $this->getMethodComment($method->getDocComment());
                            try {
                                $returnModel = class_basename(get_class($return->getModel()));
                            } catch (\Exception $e) {
                                $returnModel = '';
                            }
                            if ($return instanceof HasOne || $return instanceof BelongsTo || $return instanceof MorphOne || $return instanceof HasOneThrough) {
                                $this->addProperty($methodName, $returnModel, $comment . '[一对一关联]');
                            } elseif ($return instanceof HasMany || $return instanceof HasManyThrough || $return instanceof BelongsToMany) {
                                $this->addProperty($methodName, $returnModel ? $returnModel . '[]' : '', $comment . '[一对多关联]');
                                $this->addProperty($methodName, class_basename(Collection::class), $comment . '[一对多关联]');
                            } elseif ($return instanceof MorphTo || $return instanceof MorphMany) {
                                $this->addProperty($methodName, $returnModel ? $returnModel . '[]' : '', $comment . '[多态一对多关联]');
                                $this->addProperty($methodName, class_basename(Collection::class), $comment . '[多态一对多关联]');
                            } elseif ($return instanceof MorphToMany) {
                                $this->addProperty($methodName, $returnModel ? $returnModel . '[]' : '', $comment . '[多态多对多关联]');
                                $this->addProperty($methodName, class_basename(Collection::class), $comment . '[多态多对多关联]');
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
    }

    /**
     * 向上搜索
     * @param string $search
     * @param int    $end
     * @return false|int
     * @author CQR 2021/2/5 21:41
     */
    private function searchUp(string $search, int $end)
    {
        for ($i = $end; $i >= 0; $i--) {
            if (mb_strpos($this->content[$i], $search) !== false) {
                return $i;
            }
        }

        return false;
    }

    /**
     * 向下搜索
     * @param string $search
     * @return false|int
     * @author CQR 2021/2/5 20:34
     */
    private function searchDown(string $search)
    {
        foreach ($this->content as $key => $value) {
            if (mb_strpos($value, $search) !== false) {
                return $key;
            }
        }

        return false;
    }

    /**
     * 获取返回类型
     * @param ReflectionMethod $method
     * @return string
     * @author CQR 2020/7/24 23:22
     */
    private function getReturnType(ReflectionMethod $method): string
    {
        //如果存在返回类型声明
        $returnType = $method->getReturnType();
        if ($returnType instanceof ReflectionNamedType) {
            return $returnType->getName();
        }
        //如果存在注释返回类型声明
        $docComment = $method->getDocComment();
        if ($docComment) {
            $doc = explode(PHP_EOL, $docComment);
            foreach ($doc as $val) {
                if (Str::contains($val, '@return')) {
                    $filter = array_filter(explode(' ', trim($val)));
                    $values = array_filter(array_values($filter));

                    return end($values);
                }
            }
        }

        return 'string';
    }

    /**
     * 获取方法说明
     * @param string|bool $value
     * @return string
     * @author CQR 2020/7/24 23:22
     */
    private function getMethodComment($value): string
    {
        if ($value) {
            $doc = explode(PHP_EOL, $value);
            foreach ($doc as $key => $val) {
                if ($key > 0) {
                    $str = str_replace(['/', '*'], '', $val);
                    if (!empty($str) && strpos($val, '@') === false) {
                        return trim($str);
                    }
                }
            }
        }

        return '';
    }

    /**
     * 根据偏移量拼接数组
     * @param array $basic
     * @param array $splice
     * @param int   $offset
     * @author CQR 2021/2/5 23:35
     */
    private function spliceArrayByOffset(array &$basic, array $splice, int $offset): void
    {
        array_splice($basic, $offset, 0, $splice);
    }

    /**
     * 文件内容转成数组
     * @param string $file
     * @author CQR 2021/2/5 20:29
     */
    private function setContentToArray(string $file): void
    {
        $this->content = explode(PHP_EOL, str_replace(["\r\n", "\r"], "\n", file_get_contents($file)));
    }

    /**
     * 文件内容转成数组
     * @param string $file
     * @author CQR 2021/2/5 20:29
     */
    private function write(string $file): void
    {
        file_put_contents($file, implode(PHP_EOL, $this->content));
    }

    /**
     * 重置
     * @author CQR 2021/2/6 11:05
     */
    private function reset(): void
    {
        $this->content       = [];
        $this->databaseField = [];
        $this->schema        = [];
        $this->schemaDoc     = [];
        $this->property      = [];
    }

    /**
     * 错误信息
     * @param string $message
     * @author CQR 2021/2/5 20:06
     */
    private function error(string $message): void
    {
        $this->output->error($message);
        exit();
    }

    /**
     * 提示信息
     * @param string $message
     * @author CQR 2021/2/5 20:06
     */
    private function info(string $message): void
    {
        $this->output->info($message);
        exit();
    }

    /**
     * @note   设置table属性
     * @author CQR
     * @date   2021/3/10 13:32
     */
    protected function setTable(): void
    {
        $properties = $this->reflection->getDefaultProperties();
        if (!$properties['table']) {
            $this->table[] = $this->indent . '/**';
            $this->table[] = $this->indent . ' * @var string';
            $this->table[] = $this->indent . ' */';
            $this->table[] = $this->indent . 'protected $table = ' . "'{$this->query->getTable()}';";
            $this->table[] = '';
        }
    }

    /**
     * @note   文件内容中追加table属性
     * @param string $className
     * @author CQR
     * @date   2021/3/10 13:32
     */
    private function appendTableToContent(string $className): void
    {
        if ($this->table) {
            $classRow    = "class {$className} extends $this->parentModelName";
            $classRowNum = array_search($classRow, $this->content);
            $this->spliceArrayByOffset($this->content, $this->table, $classRowNum + 2);
        }
    }
}
