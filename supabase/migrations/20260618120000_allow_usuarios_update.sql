DROP POLICY IF EXISTS "Permitir atualizar usuarios" ON "public"."usuarios";

CREATE POLICY "Permitir atualizar usuarios"
ON "public"."usuarios"
AS PERMISSIVE
FOR UPDATE
TO anon, authenticated
USING (true)
WITH CHECK (true);
