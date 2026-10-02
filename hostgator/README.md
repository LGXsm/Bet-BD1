# Bet BD1 em lgmartinez.com

Esta versão publica o jogo na raiz de `https://lgmartinez.com/` usando PHP da HostGator. Ela reproduz o jogo do Worker original e usa um arquivo de estado protegido por `flock` para manter placar, participantes e banco de perguntas entre requisições. A pasta `bet_bd1_private`, que guarda estado e segredos, fica **fora** da pasta pública `lgmartinez.com`.

O envio por FTPS é passivo e mantém as outras pastas do domínio, inclusive `/clientes/`. O jogo do Sites permanece independente; os dois ambientes não compartilham partidas ou alterações feitas no painel do criador.

## Publicar uma atualização

1. Edite `src/index.html` ou `hostgator/api.php` no projeto.
2. Execute `python hostgator/deploy.py --code-only --dry-run` para conferir o destino e depois `python hostgator/deploy.py --code-only` para publicar sem substituir o banco de perguntas ou a configuração privada da hospedagem.

`hostgator/.env` contém as credenciais FTP e o novo código do criador; ele é ignorado pelo Git. Nunca copie esse arquivo para a pasta pública nem para um pacote compartilhado. Em outra máquina, copie `hostgator/.env.example` para `hostgator/.env` e preencha os valores.

O primeiro acesso ao jogo cria `bet_bd1_private/state.json`. Republicar os arquivos não apaga o estado. O banco inicial de 45 perguntas é usado somente quando o estado ainda não existe; depois disso, edições feitas pelo criador no site são preservadas.
