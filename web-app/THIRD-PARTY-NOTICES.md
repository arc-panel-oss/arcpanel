# Third-party components of the panel app

The panel app (`web-app/`) ships these components in `public/build/`, built by `build.js` from `node_modules`.

| Component                          | Use                                              | Licence                                                          |
| ---------------------------------- | ------------------------------------------------ | ---------------------------------------------------------------- |
| Inter (`@fontsource/inter`)        | interface font, woff2 latin, latin-ext, vietnamese | SIL Open Font License 1.1 (`build/fonts/inter-LICENSE.txt`)      |
| JetBrains Mono (`@fontsource/jetbrains-mono`) | monospace font, same subsets        | SIL Open Font License 1.1 (`build/fonts/jetbrains-mono-LICENSE.txt`) |
| Alpine.js, `@alpinejs/collapse`    | page behaviour                                   | MIT                                                              |
| Chart.js                           | server graphs on the dashboard                   | MIT                                                              |
| check-password-strength            | password strength meter                          | MIT                                                              |

Icons are the ArcPanel Nocturne sprite (`resources/views/components/icons.blade.php`), part of this project.
