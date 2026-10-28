# fluid-lsp

Language server for TYPO3 Fluid templates. Supports TYPO3 12.4, 13.4 and 14.

- Completion: ViewHelper names (tag and inline syntax) and their arguments
- Hover: ViewHelper and argument documentation
- Go to definition: the ViewHelper's PHP class
- Diagnostics: unknown ViewHelpers, unknown arguments and missing required arguments

Global namespaces, `xmlns:` declarations and `{namespace}` declarations are all picked up.

## Usage

Nothing is installed in the project. The server boots the project's own TYPO3, because only the project knows which
ViewHelpers are installed:

```sh
bin/fluid-lsp /path/to/typo3-project
```

Deprecations are muted: host PHP is often newer than the project's.

## Neovim

```lua
local bin = vim.fn.expand("~/Development/fluid-lsp/bin/fluid-lsp")

vim.lsp.config("fluid_lsp", {
  filetypes = { "fluid" },
  root_dir = function(bufnr, on_dir)
    for dir in vim.fs.parents(vim.api.nvim_buf_get_name(bufnr)) do
      if vim.uv.fs_stat(dir .. "/vendor/typo3/cms-core") then
        return on_dir(dir)
      end
    end
  end,
  cmd = function(dispatchers, config)
    return vim.lsp.rpc.start({ bin, config.root_dir }, dispatchers)
  end,
})
vim.lsp.enable("fluid_lsp")
```

## Development

```sh
composer ci                          # PHPStan, PHPUnit, php-cs-fixer
php Build/smoke-test.php Build/v13   # end-to-end against a real instance
```

`Build/v12`, `Build/v13` and `Build/v14` hold one composer.json each. Set one up with `composer install` and
`vendor/bin/typo3 setup --driver=sqlite`. The smoke test also works against any real project path.
