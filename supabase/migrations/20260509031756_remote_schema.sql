drop extension if exists "pg_net";

drop policy "Enable read access for all users" on "public"."usuarios";


  create policy "Enable read access for all users"
  on "public"."usuarios"
  as permissive
  for select
  to anon, authenticated
using (true);



