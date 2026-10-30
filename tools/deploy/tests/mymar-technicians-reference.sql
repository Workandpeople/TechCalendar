-- Previous query, retained only for equivalence/performance tests in a disposable container.
SELECT JSON_OBJECT('section','technician','mymar_id',u.id,'mymar_name',u.name,'enabled_raw',u.enabled,'cofrac_id',u.cofrac_id,
 'historical_source_duplicates',(SELECT COUNT(*) FROM MYMAR.users other WHERE other.cofrac_id=u.cofrac_id),
 'historical_v2_id',v.id,'historical_v2_name',CONCAT_WS(' ',v.first_name,v.last_name),'historical_v2_role',v.role,
 'historical_v2_disabled',IF(v.id IS NULL,NULL,v.deleted_at IS NOT NULL),'historical_v2_external',IF(v.id IS NULL,NULL,v.external_client_id IS NOT NULL),
 'historical_email_matches',IF(TRIM(COALESCE(u.email,''))='' OR TRIM(COALESCE(v.email,''))='',NULL,
 CAST(LOWER(TRIM(CONVERT(u.email USING utf8mb4))) AS BINARY)=CAST(LOWER(TRIM(CONVERT(v.email USING utf8mb4))) AS BINARY)),
 'email_candidates',COALESCE(e.candidates,0),'unique_email_v2_id',IF(e.candidates=1,e.v2_id,NULL),
 'unique_email_v2_name',IF(e.candidates=1,e.v2_name,NULL),'unique_email_v2_role',IF(e.candidates=1,e.v2_role,NULL),
 'unique_email_v2_disabled',IF(e.candidates=1,e.disabled,NULL),'unique_email_v2_external',IF(e.candidates=1,e.external_account,NULL))
 FROM MYMAR.users u LEFT JOIN cofrac2production.coffrac_user_links l ON l.source_user_id=u.cofrac_id
 LEFT JOIN cofrac2production.users v ON v.id=l.user_id
 LEFT JOIN (SELECT CAST(LOWER(TRIM(CONVERT(email USING utf8mb4))) AS BINARY) email_key,COUNT(*) candidates,MIN(id) v2_id,
 MIN(CONCAT_WS(' ',first_name,last_name)) v2_name,MIN(role) v2_role,MAX(deleted_at IS NOT NULL) disabled,MAX(external_client_id IS NOT NULL) external_account
 FROM cofrac2production.users WHERE TRIM(COALESCE(email,''))<>'' GROUP BY email_key) e
 ON e.email_key=CAST(LOWER(TRIM(CONVERT(u.email USING utf8mb4))) AS BINARY)
 WHERE u.id IN (SELECT technicien_id FROM MYMAR.dossiers WHERE technicien_id IS NOT NULL
 UNION SELECT auditeur_id FROM MYMAR.dossiers WHERE auditeur_id IS NOT NULL
 UNION SELECT b.model_id FROM MYMAR.model_has_roles b JOIN MYMAR.roles role ON role.id=b.role_id
 WHERE b.model_type IN (CONCAT('App',CHAR(92),'Models',CHAR(92),'User'),CONCAT('App',CHAR(92),'User'))
 AND (LOWER(role.name) LIKE '%techn%' OR LOWER(role.name) LIKE '%audit%')) ORDER BY u.id LIMIT 2001;
