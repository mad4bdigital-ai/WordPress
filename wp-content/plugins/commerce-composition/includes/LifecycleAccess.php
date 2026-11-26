<?php
declare(strict_types=1);
namespace Commerce\Composition;
/** No local grant or fallback: the native lifecycle provider remains authoritative. */
final class LifecycleAccess {
 public static function available():bool {return class_exists('MAD4B_Lifecycle_Access')&&is_callable(['MAD4B_Lifecycle_Access','can']);}
 public static function can(string $cap,?int $actor=null):bool {
  return self::available()&&($actor===null||$actor>0)&&\MAD4B_Lifecycle_Access::can($cap,$actor);
 }
}
