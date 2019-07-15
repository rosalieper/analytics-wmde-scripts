SELECT
	page_namespace AS namespace,
	pp_value AS statements,
	COUNT(*) AS count,
	pp_propname
FROM wikidatawiki.page_props
JOIN wikidatawiki.page ON pp_page = page.page_id
WHERE pp_propname IN ( 'wb-claims', 'wb-identifiers' )
AND page.page_namespace IN ( 0, 120 )
GROUP BY pp_value, page_namespace, pp_propname
