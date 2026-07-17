-- One-row data repair: member 33362 avatar carries a legacy concatenation —
-- an S3 base path (.../members/33362/photo/) glued in front of a value that
-- was ALREADY an absolute URL. The API now cleans this at emission time
-- (App\Support\AvatarUrl::clean), so this repair is cosmetic-at-rest; run it
-- manually when convenient. NOT wired into any migration or command.
--
-- SELECT FIRST — confirm the row actually shows the glued value (a second
-- "http" embedded mid-string) before updating. users.avatar is a plain
-- string; agents.avatar is a JSON array of strings, check both:

SELECT id, avatar FROM users  WHERE id = 33362;
SELECT id, user_id, avatar FROM agents WHERE user_id = 33362;

-- Repair (users.avatar, string column): keep everything from the LAST
-- embedded "http" onward — same rule as AvatarUrl::clean. The WHERE guard
-- (a second "http" occurring after position 1) makes the statement a no-op
-- if the row was already fixed, so it is safe to re-run.

UPDATE users
SET avatar = CONCAT('http', SUBSTRING_INDEX(avatar, 'http', -1))
WHERE id = 33362
  AND LOCATE('http', avatar, 2) > 1;

-- If the SELECT shows the glued value on the agents row instead (JSON array,
-- single element), apply the same rule inside the array:
--
-- UPDATE agents
-- SET avatar = JSON_ARRAY(
--         CONCAT('http', SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(avatar, '$[0]')), 'http', -1))
--     )
-- WHERE user_id = 33362
--   AND LOCATE('http', JSON_UNQUOTE(JSON_EXTRACT(avatar, '$[0]')), 2) > 1;
