<?php
/**
 * 블루 클러스터 에이전트 설정값.
 */

const BCL_MODULE_KEY = 'bluecluster';

/** 리더 리스 유지 시간(초) */
const BCL_LEASE_S = 3;
/** 노드 생존 판정 시간(초) */
const BCL_NODE_TTL_S = 3;

/** 시도 결과 원본을 쌓는 기본 폴더 */
const BCL_FILES_DIR_DEFAULT = '/var/www/iworks-data/bluecluster';

/** piece_id 최대 글자 수(bcl_pieces.piece_key 길이) */
const BCL_PIECE_ID_MAX = 80;

/** 조각 finish 가 받는 status */
const BCL_FINISH_STATUSES = ['done', 'stopped_by_owner', 'lost'];
/** 조각 close 가 받는 status */
const BCL_CLOSE_STATUSES = ['lost'];
