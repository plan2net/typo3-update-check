Output documentation/demo.gif

Set Theme { "background": "#0d1117", "foreground": "#c9d1d9", "black": "#484f58", "red": "#ff7b72", "green": "#7ee787", "yellow": "#e3b341", "blue": "#79c0ff", "magenta": "#d2a8ff", "cyan": "#a5d6ff", "white": "#c9d1d9", "brightBlack": "#6e7681", "brightRed": "#ffa198", "brightGreen": "#56d364", "brightYellow": "#e3b341", "brightBlue": "#79c0ff", "brightMagenta": "#d2a8ff", "brightCyan": "#a5d6ff", "brightWhite": "#f0f6fc" }
Set FontSize 22
Set Width 1320
Set Height 1080
Set Padding 22
Set TypingSpeed 5ms
Set Framerate 30
Set Shell "bash"

Hide
Type `export PS1='$ ' && export TERM=xterm-256color && export COLORTERM=truecolor && clear`
Enter
Sleep 300ms
Type `ddev exec 'cd /tmp/typo3-demo && composer require typo3/cms-core:"13.4.12" -W'`
Sleep 300ms
Show
Enter
Sleep 12s

Hide
Type "y"
Show
Enter
Sleep 6s
