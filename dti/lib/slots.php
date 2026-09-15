<?php
/**
 * 자료 칸. 이름이 곧 컬럼 접두어다 — 발표자료(material)는 발표 행에, 스캔 원본(scan)은
 * 아티클 행에 있다. 어느 쪽이든 kind·name·url·path 네 컬럼 한 벌이다.
 */

const DTI_SLOT_NAMES = ['material', 'scan'];

function dti_slot_check($slot) {
    if (!in_array($slot, DTI_SLOT_NAMES, true)) {
        throw new DtiError('없는 자료 칸입니다', 404);
    }
    return $slot;
}

function dti_slot_column($slot, $field) {
    return "{$slot}_{$field}";
}

/** 스캔 원본은 아티클 행에, 발표자료는 발표 행에 있다 */
function dti_slot_on_topic($slot) {
    return $slot === 'scan';
}

function dti_slot_holder($slot, array $topic, $pres) {
    return dti_slot_on_topic($slot) ? $topic : $pres;
}

function dti_slot_get($holder, $slot, $field) {
    return $holder === null ? null : $holder[dti_slot_column($slot, $field)];
}

/** @param array $values kind·name·url·path 중 채울 것 */
function dti_slot_set(array &$holder, $slot, array $values) {
    foreach ($values as $field => $value) {
        $holder[dti_slot_column($slot, $field)] = $value;
    }
}
