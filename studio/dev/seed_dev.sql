-- =====================================================================
-- BlueStudio 로컬 개발용 샘플 데이터
--   mysql -u root iworks_local < studio/dev/seed_dev.sql
--
-- 운영 DB 에서 실행하지 마세요. 아래 DELETE 가 프로젝트를 전부 지웁니다.
-- =====================================================================
SET NAMES utf8mb4;

DELETE FROM bs_project_source;
DELETE FROM bs_project;
ALTER TABLE bs_project AUTO_INCREMENT = 1;

INSERT INTO bs_project
  (code, name, summary, client, track, dev_start, dev_end, test_start, test_end,
   deploy_date, notes, status, owner_id, owner_name) VALUES
('PRJ-2026-001', 'A대 LXP 고도화', '학습경험 플랫폼 2차 고도화', 'A대학교', 'lxp_campus',
 '2026-03-02','2026-05-29','2026-05-11','2026-06-12','2026-06-22','출석 연동 주의','allocating',
 'kimhy@bluesoft.co.kr','김호영'),
('PRJ-2026-002', 'B대 LMS 신규 구축', '무들 기반 신규 구축', 'B대학교', 'lms_b2b',
 '2026-04-01','2026-08-31','2026-08-01','2026-09-15','2026-09-25',NULL,'scoping',
 'kimhy@bluesoft.co.kr','김호영'),
('PRJ-2026-003', '사내 포털 개선', 'iworks 개선 작업', NULL, '사내',
 '2026-07-01','2026-08-31',NULL,NULL,NULL,NULL,'draft',
 'jian@bluesoft.co.kr','김지안'),
('PRJ-2026-004', 'C대 공유대학 연계', '혁신융합 학점교류 연계', 'C대학교', 'lxp_hybrid',
 '2026-02-01','2026-04-30','2026-04-15','2026-05-20','2026-05-30',NULL,'running',
 'scpark@bluesoft.co.kr','박성철'),
('PRJ-2026-005', 'D대 유지보수 이관', '운영 이관 및 안정화', 'D대학교', '용역',
 '2026-09-01','2026-11-30',NULL,NULL,NULL,'보류 상태','hold',
 'kimhy@bluesoft.co.kr','김호영'),
('PRJ-2025-001', '지난해 유지보수', '2025년 정기 유지보수', 'A대학교', '용역',
 '2025-01-06','2025-12-20',NULL,NULL,'2025-12-27',NULL,'done',
 'kimhy@bluesoft.co.kr','김호영');

-- 출처 문서 몇 건 (파일 없이 링크·텍스트만 — 실제 파일은 화면에서 올려 본다)
INSERT INTO bs_project_source
  (project_id, kind, title, url, parsed_text, parse_status, uploaded_by, uploaded_by_name) VALUES
(1,'figma','A대 LXP 화면 시안','https://www.figma.com/file/sample/ADesign',NULL,'skip',
 'kimhy@bluesoft.co.kr','김호영'),
(1,'text','킥오프 회의록',NULL,'출석부 통합 요건 정리\n1. 온라인/오프라인 통합 출석\n2. 성적부 연동','ok',
 'kimhy@bluesoft.co.kr','김호영'),
(2,'url','요구사항 정의서(드라이브)','https://drive.google.com/file/sample',NULL,'skip',
 'kimhy@bluesoft.co.kr','김호영');

-- ---------------------------------------------------------------------
-- WBS 샘플 (PRJ-2026-001 A대 LXP 고도화)
--
-- 화면을 열자마자 볼 것이 있어야 편집기를 시험할 수 있어서 넣어 둡니다.
-- 확정 여부를 섞어 두었습니다 — 확정한 것만 배정 대상이 되는 것을
-- 눈으로 확인하려면 둘 다 있어야 합니다.
--
-- wbs_no / depth / seq 는 여기서 직접 적지만, 화면에서 한 번 저장하면
-- TaskRepo 가 자리 기준으로 다시 매깁니다. 값이 다르면 그쪽이 맞습니다.
-- bs_project 를 위에서 지웠으므로 FK CASCADE 로 옛 태스크는 이미 없습니다.
-- ---------------------------------------------------------------------
INSERT INTO bs_task
  (project_id, parent_id, depth, seq, wbs_no, title, description, est_md, difficulty,
   plan_start, plan_end, origin, confirmed, status, progress_pct) VALUES
(1, NULL, 1, 0, '1', '출석 통합', '온라인·오프라인 출석을 하나로 본다', NULL, NULL,
 '2026-03-02','2026-04-10','manual', 0, 'todo', 0);
SET @t1 = LAST_INSERT_ID();
INSERT INTO bs_task
  (project_id, parent_id, depth, seq, wbs_no, title, description, est_md, difficulty,
   plan_start, plan_end, origin, confirmed, status, progress_pct) VALUES
(1, @t1, 2, 0, '1.1', '출석 데이터 모델 정리', NULL, 4, 3, '2026-03-02','2026-03-13','manual', 1, 'done', 100);
-- 주의: 여러 행을 한 INSERT 로 넣으면 LAST_INSERT_ID() 는 **첫 행**의 id 를 준다.
-- 처음에 둘을 묶어 넣었다가 1.2.1 / 1.2.2 가 1.1 밑으로 붙었다. 부모로 쓸 행은 따로 넣는다.
INSERT INTO bs_task
  (project_id, parent_id, depth, seq, wbs_no, title, description, est_md, difficulty,
   plan_start, plan_end, origin, confirmed, status, progress_pct) VALUES
(1, @t1, 2, 1, '1.2', '통합 출석부 화면', '주차별 보기 + 일괄 처리', NULL, 4, '2026-03-16','2026-04-10','manual', 1, 'doing', 40);
SET @t12 = LAST_INSERT_ID();
INSERT INTO bs_task
  (project_id, parent_id, depth, seq, wbs_no, title, description, est_md, difficulty,
   plan_start, plan_end, origin, confirmed, status, progress_pct) VALUES
(1, @t12, 3, 0, '1.2.1', '주차별 보기', NULL, 5, 3, '2026-03-16','2026-03-27','manual', 1, 'doing', 60),
(1, @t12, 3, 1, '1.2.2', '일괄 출결 처리', '엑셀 업로드 포함', 4, 4, '2026-03-30','2026-04-10','manual', 0, 'todo', 0);

INSERT INTO bs_task
  (project_id, parent_id, depth, seq, wbs_no, title, description, est_md, difficulty,
   plan_start, plan_end, origin, confirmed, status, progress_pct) VALUES
(1, NULL, 1, 1, '2', '성적부 연동', '출석 결과를 성적부로 넘긴다', NULL, NULL,
 '2026-04-13','2026-05-29','manual', 0, 'todo', 0);
SET @t2 = LAST_INSERT_ID();
INSERT INTO bs_task
  (project_id, parent_id, depth, seq, wbs_no, title, description, est_md, difficulty,
   plan_start, plan_end, origin, confirmed, status, progress_pct) VALUES
(1, @t2, 2, 0, '2.1', '성적 산출 규칙 반영', NULL, 6, 4, '2026-04-13','2026-05-01','manual', 1, 'todo', 0),
(1, @t2, 2, 1, '2.2', '마이그레이션 검증', '지난 학기 데이터로 대조', 3, 5, '2026-05-04','2026-05-29','manual', 0, 'hold', 0);

-- 분야 태그. 가중치는 고른 개수로 균등하게 — TaskRepo::replaceDomains() 와 같은 규칙.
INSERT INTO bs_task_domain (task_id, domain_id, weight)
SELECT t.id, d.id, 1.000
  FROM bs_task t JOIN bs_domain d ON d.code = 'attendance'
 WHERE t.project_id = 1 AND t.wbs_no IN ('1.1','1.2','1.2.1','1.2.2');
INSERT INTO bs_task_domain (task_id, domain_id, weight)
SELECT t.id, d.id, 0.500
  FROM bs_task t JOIN bs_domain d ON d.code IN ('gradebook','migration')
 WHERE t.project_id = 1 AND t.wbs_no = '2.2';
INSERT INTO bs_task_domain (task_id, domain_id, weight)
SELECT t.id, d.id, 1.000
  FROM bs_task t JOIN bs_domain d ON d.code = 'gradebook'
 WHERE t.project_id = 1 AND t.wbs_no = '2.1';
