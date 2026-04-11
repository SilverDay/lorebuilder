<script>
/**
 * StoryEditor — Milkdown WYSIWYG Markdown editor for story content.
 *
 * Uses @milkdown/vue with commonmark preset and listener plugin
 * to provide a rich editing experience that stores Markdown natively.
 *
 * Structure: outer component renders MilkdownProvider, inner component
 * calls useEditor (which requires inject from MilkdownProvider parent).
 * Toolbar uses editor.action(callCommand(...)) to toggle marks/blocks.
 */
import { defineComponent, h, watch } from 'vue'
import { Editor, rootCtx, defaultValueCtx } from '@milkdown/core'
import { commonmark,
  toggleStrongCommand, toggleEmphasisCommand, toggleInlineCodeCommand,
  wrapInBlockquoteCommand, wrapInBulletListCommand, wrapInOrderedListCommand,
  wrapInHeadingCommand, insertHrCommand, createCodeBlockCommand,
} from '@milkdown/preset-commonmark'
import { listener, listenerCtx } from '@milkdown/plugin-listener'
import { history } from '@milkdown/plugin-history'
import { nord } from '@milkdown/theme-nord'
import { Milkdown, MilkdownProvider, useEditor, useInstance } from '@milkdown/vue'
import { replaceAll, callCommand } from '@milkdown/utils'

import '@milkdown/theme-nord/style.css'

/**
 * Toolbar buttons definition.
 */
const toolbarButtons = [
  { label: 'B', title: 'Bold (Ctrl+B)', command: toggleStrongCommand, group: 'inline' },
  { label: 'I', title: 'Italic (Ctrl+I)', command: toggleEmphasisCommand, group: 'inline' },
  { label: '<>', title: 'Inline Code', command: toggleInlineCodeCommand, group: 'inline' },
  { label: 'H1', title: 'Heading 1', command: wrapInHeadingCommand, payload: 1, group: 'block' },
  { label: 'H2', title: 'Heading 2', command: wrapInHeadingCommand, payload: 2, group: 'block' },
  { label: 'H3', title: 'Heading 3', command: wrapInHeadingCommand, payload: 3, group: 'block' },
  { label: '❝', title: 'Blockquote', command: wrapInBlockquoteCommand, group: 'block' },
  { label: '•', title: 'Bullet List', command: wrapInBulletListCommand, group: 'list' },
  { label: '1.', title: 'Ordered List', command: wrapInOrderedListCommand, group: 'list' },
  { label: '—', title: 'Horizontal Rule', command: insertHrCommand, group: 'insert' },
  { label: '```', title: 'Code Block', command: createCodeBlockCommand, group: 'insert' },
]

/**
 * Inner component — must be a child of MilkdownProvider so inject() works.
 */
const MilkdownEditorInner = defineComponent({
  name: 'MilkdownEditorInner',
  props: {
    content: { type: String, default: '' },
  },
  emits: ['update:content'],
  setup(props, { emit }) {
    let lastEmittedContent = props.content || ''

    useEditor((root) => {
      return Editor.make()
        .config(nord)
        .config((ctx) => {
          ctx.set(rootCtx, root)
          ctx.set(defaultValueCtx, props.content || '')
          ctx.get(listenerCtx)
            .markdownUpdated((_ctx, markdown, prevMarkdown) => {
              if (markdown !== prevMarkdown) {
                lastEmittedContent = markdown
                emit('update:content', markdown)
              }
            })
        })
        .use(commonmark)
        .use(listener)
        .use(history)
    })

    const [loading, getEditor] = useInstance()

    function execCommand(cmd, payload) {
      const editor = getEditor()
      if (!editor || !cmd.key) return
      editor.action(callCommand(cmd.key, payload))
    }

    // Watch for external content changes (e.g., story reload)
    // Skip if the new value matches what we last emitted (avoids echo loop)
    watch(() => props.content, (newVal) => {
      if (loading.value) return
      if (newVal === lastEmittedContent) return
      const editor = getEditor()
      if (!editor) return
      lastEmittedContent = newVal || ''
      editor.action(replaceAll(newVal || ''))
    })

    return () => h('div', { class: 'story-editor' }, [
      // Toolbar
      h('div', { class: 'story-editor__toolbar' },
        toolbarButtons.map((btn, i) => {
          const prev = toolbarButtons[i - 1]
          const sep = prev && prev.group !== btn.group
            ? [h('span', { class: 'story-editor__toolbar-sep' })]
            : []
          return [
            ...sep,
            h('button', {
              type: 'button',
              class: 'story-editor__toolbar-btn',
              title: btn.title,
              disabled: loading.value,
              onMousedown: (e) => {
                e.preventDefault() // keep editor focus
                execCommand(btn.command, btn.payload)
              },
            }, btn.label),
          ]
        }).flat(),
      ),
      // Editor
      loading.value ? h('div', { class: 'story-editor__loading' }, 'Loading editor…') : null,
      h(Milkdown),
    ])
  },
})

export default defineComponent({
  name: 'StoryEditor',
  props: {
    content: { type: String, default: '' },
  },
  emits: ['update:content'],
  setup(props, { emit }) {
    return () => h(MilkdownProvider, null, {
      default: () => h(MilkdownEditorInner, {
        content: props.content,
        'onUpdate:content': (val) => emit('update:content', val),
      }),
    })
  },
})
</script>
