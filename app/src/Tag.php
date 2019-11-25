<?php
declare(strict_types=1);

namespace GuzabaPlatform\Tags;

use Guzaba2\Orm\ActiveRecordWithAuthorization;
use GuzabaPlatofrm\Tags\Base\Interfaces\TagInterface;

/**
 * Class Tag
 * @package Azonmedia\Tags
 * @property string tag_name
 */
class Tag extends ActiveRecordWithAuthorization implements TagInterface
{

    public function get_name(): string
    {
        return $this->tag_name;
    }

    public static function create(string $tag_name): object
    {
        $Tag = new self();
        $Tag->tag_name = $tag_name;
        $Tag->save();
        return $Tag;
    }
}