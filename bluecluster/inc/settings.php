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

/** piece_id 형식 */
const BCL_PIECE_ID_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,78}[A-Za-z0-9])?$/D';
/** Windows 예약 파일 이름. 확장자가 붙어도 예약이다 */
const BCL_RESERVED_NAME_PATTERN = '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/iD';
/** node_id 최대 글자 수(bcl_nodes.node_id 길이) */
const BCL_NODE_ID_MAX = 80;

/** 조각 finish 가 받는 status */
const BCL_FINISH_STATUSES = ['done', 'stopped_by_owner', 'lost'];
/** 조각 close 가 받는 status */
const BCL_CLOSE_STATUSES = ['lost'];
