# Deploy no Railway

Este projeto agora esta em PHP/HTML/CSS/JS.

## Start

O Railway usa:

```bash
php -S 0.0.0.0:$PORT index.php
```

## Banco de dados

Em producao, o Supabase e a base principal do sistema.

O SQLite local (`banco_diarq.db`) existe apenas para testes locais e como cache/espelho temporário durante a execução. Ele não deve ser enviado para o Git nem tratado como banco oficial do Railway.

Quando o Supabase estiver configurado, o sistema não copia o banco local da raiz e não importa automaticamente as planilhas locais no login.

## Variáveis obrigatórias

- `SUPABASE_URL`
- `SUPABASE_KEY` ou `SUPABASE_ANON_KEY`

As gravações de acervo, usuários e indicadores tentam salvar primeiro no Supabase. Se o Supabase falhar, a gravação local é interrompida.

O schema antigo já tem `inventário` e `usuarios`. Aplique também:

- `supabase/migrations/20260615213000_add_indicadores.sql`
- `supabase/migrations/20260618120000_allow_usuarios_update.sql`

## Variaveis opcionais

- `DATA_DIR`: caminho alternativo para dados locais de teste/cache.
- `OPENAI_API_KEY`: ativa o assistente virtual.
- `OPENAI_MODEL`: modelo do assistente, padrão `gpt-4.1-mini`.

## Excel e PDF

O Railway instala as bibliotecas via Composer:

- `phpoffice/phpspreadsheet` para exportacao `.xlsx`
- `setasign/fpdf` para Etiqueta e Guia Fora em PDF

## Primeiro login

- Usuario: `ADMIN`
- Senha: `admin`
