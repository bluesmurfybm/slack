<?php
/**
 * 자료 칸. 발표자료(material)와 스캔 원본(scan) 둘뿐이다.
 * 이름은 API 경로이자, 응답에서 첫 자료를 싣는 키의 접두어다(material_kind 등).
 */

const DTI_SLOT_NAMES = ['material', 'scan'];

function dti_slot_check(string $slot): string {
    if (!in_array($slot, DTI_SLOT_NAMES, true)) {
        throw new DtiError('없는 자료 칸입니다', 404);
    }
    return $slot;
}

function dti_slot_column(string $slot, string $field): string {
    return "{$slot}_{$field}";
}
