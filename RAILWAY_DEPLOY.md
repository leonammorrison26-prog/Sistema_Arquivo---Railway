# Deploy no Railway

Este projeto agora esta em PHP/HTML/CSS/JS.

## Start

O Railway usa:

```bash
php -S 0.0.0.0:$PORT index.php
```

## Banco de dados

Em producao, o Supabase e a base principal do sistema.

O SQLite local (`banco_diarq.db`) existe apenas para testes locais e como cache/espelho temporario durante a execucao. Ele nao deve ser enviado para o Git nem tratado como banco oficial do Railway.

Quando o Supabase estiver configurado, o sistema nao copia o banco local da raiz e nao importa automaticamente as planilhas locais no login.

## Variaveis obrigatorias

- `SUPABASE_URL`
- `SUPABASE_KEY` ou `SUPABASE_ANON_KEY`

As gravacoes de acervo, usuarios, indicadores e mapa de acervo tentam salvar primeiro no Supabase. Se o Supabase falhar, a gravacao local e interrompida.

O schema antigo ja tem `inventario` e `usuarios`. Aplique tambem:

- `supabase/migrations/20260615213000_add_indicadores.sql`
- `supabase/migrations/20260618120000_allow_usuarios_update.sql`
- `supabase/migrations/20260626160000_add_mapa_acervo.sql`

## Variaveis opcionais

- `DATA_DIR`: caminho alternativo para dados locais de teste/cache.
- `OPENAI_API_KEY`: ativa o assistente virtual.
- `OPENAI_MODEL`: modelo do assistente, padrao `gpt-4.1-mini`.

## Excel e PDF

O Railway instala as bibliotecas via Composer:

- `phpoffice/phpspreadsheet` para exportacao `.xlsx`
- `setasign/fpdf` para Etiqueta e Guia Fora em PDF

## Primeiro login

- Usuario: `ADMIN`
- Senha: `admin`
