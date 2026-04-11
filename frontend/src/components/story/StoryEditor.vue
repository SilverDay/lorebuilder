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
import { defineComponent, h, ref, watch, onMounted, onUnmounted } from 'vue'
import { Editor, rootCtx, defaultValueCtx, editorViewCtx } from '@milkdown/core'
import { commonmark,
  toggleStrongCommand, toggleEmphasisCommand, toggleInlineCodeCommand,
  wrapInBlockquoteCommand, wrapInBulletListCommand, wrapInOrderedListCommand,
  wrapInHeadingCommand, insertHrCommand, createCodeBlockCommand,
  turnIntoTextCommand, toggleLinkCommand,
} from '@milkdown/preset-commonmark'
import { gfm, toggleStrikethroughCommand } from '@milkdown/preset-gfm'
import { listener, listenerCtx } from '@milkdown/plugin-listener'
import { history, undoCommand, redoCommand } from '@milkdown/plugin-history'
import { nord } from '@milkdown/theme-nord'
import { Milkdown, MilkdownProvider, useEditor, useInstance } from '@milkdown/vue'
import { replaceAll, callCommand } from '@milkdown/utils'
import { lift, toggleMark } from 'prosemirror-commands'

import '@milkdown/theme-nord/style.css'

/**
 * Toolbar buttons definition.
 * `active`: function (view) => boolean — highlights button when active.
 * `toggle`: 'blockquote' | 'bullet_list' | 'ordered_list' — uses lift when already active.
 */
const toolbarButtons = [
  { label: '↩', title: 'Undo (Ctrl+Z)', command: undoCommand, group: 'history' },
  { label: '↪', title: 'Redo (Ctrl+Y)', command: redoCommand, group: 'history' },
  { label: 'B', title: 'Bold (Ctrl+B)', command: toggleStrongCommand, group: 'inline',
    markName: 'strong',
    active: (v) => isMarkActive(v, 'strong') },
  { label: 'I', title: 'Italic (Ctrl+I)', command: toggleEmphasisCommand, group: 'inline',
    markName: 'emphasis',
    active: (v) => isMarkActive(v, 'emphasis') },
  { label: 'S', title: 'Strikethrough (~~text~~)', command: toggleStrikethroughCommand, group: 'inline',
    markName: 'strikethrough',
    style: 'text-decoration: line-through',
    active: (v) => isMarkActive(v, 'strikethrough') },
  { label: '<>', title: 'Inline Code', command: toggleInlineCodeCommand, group: 'inline',
    markName: 'inlineCode',
    active: (v) => isMarkActive(v, 'inlineCode') },
  { label: '🔗', title: 'Link', command: toggleLinkCommand, payload: { href: '' }, group: 'inline',
    active: (v) => isMarkActive(v, 'link') },
  { label: '¶', title: 'Normal Text', command: turnIntoTextCommand, group: 'block',
    active: (v) => isNodeActive(v, 'paragraph') },
  { label: 'H1', title: 'Heading 1', command: wrapInHeadingCommand, payload: 1, group: 'block',
    active: (v) => isHeadingActive(v, 1) },
  { label: 'H2', title: 'Heading 2', command: wrapInHeadingCommand, payload: 2, group: 'block',
    active: (v) => isHeadingActive(v, 2) },
  { label: 'H3', title: 'Heading 3', command: wrapInHeadingCommand, payload: 3, group: 'block',
    active: (v) => isHeadingActive(v, 3) },
  { label: '❝', title: 'Blockquote (toggle)', command: wrapInBlockquoteCommand, group: 'wrap',
    toggle: 'blockquote',
    active: (v) => isNodeActive(v, 'blockquote') },
  { label: '•', title: 'Bullet List (toggle)', command: wrapInBulletListCommand, group: 'list',
    toggle: 'bullet_list',
    active: (v) => isNodeActive(v, 'bullet_list') },
  { label: '1.', title: 'Ordered List (toggle)', command: wrapInOrderedListCommand, group: 'list',
    toggle: 'ordered_list',
    active: (v) => isNodeActive(v, 'ordered_list') },
  { label: '—', title: 'Horizontal Rule', command: insertHrCommand, group: 'insert' },
  { label: '```', title: 'Code Block (toggle)', command: createCodeBlockCommand, group: 'insert',
    toggleToText: 'code_block',
    active: (v) => isNodeActive(v, 'code_block') },
]

function isMarkActive(view, markName) {
  if (!view) return false
  const { state } = view
  const markType = state.schema.marks[markName]
  if (!markType) return false
  const { from, $from, to, empty } = state.selection
  if (empty) return !!markType.isInSet(state.storedMarks || $from.marks())
  let found = false
  state.doc.nodesBetween(from, to, (node) => {
    if (found) return false
    found = markType.isInSet(node.marks) != null
  })
  return found
}

function isNodeActive(view, nodeName) {
  if (!view) return false
  const { state } = view
  const nodeType = state.schema.nodes[nodeName]
  if (!nodeType) return false
  const { $from, $to } = state.selection
  for (let d = $from.depth; d >= 0; d--) {
    if ($from.node(d).type === nodeType) return true
  }
  if ($to.depth !== $from.depth) {
    for (let d = $to.depth; d >= 0; d--) {
      if ($to.node(d).type === nodeType) return true
    }
  }
  return false
}

function isHeadingActive(view, level) {
  if (!view) return false
  const { state } = view
  const nodeType = state.schema.nodes.heading
  if (!nodeType) return false
  const { $from } = state.selection
  for (let d = $from.depth; d >= 0; d--) {
    const node = $from.node(d)
    if (node.type === nodeType && node.attrs.level === level) return true
  }
  return false
}

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
    const activeStates = ref([])

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
        .use(gfm)
        .use(listener)
        .use(history)
    })

    const [loading, getEditor] = useInstance()

    function updateActiveStates() {
      const editor = getEditor()
      if (!editor) return
      try {
        const view = editor.action((ctx) => ctx.get(editorViewCtx))
        activeStates.value = toolbarButtons.map((btn) =>
          btn.active ? btn.active(view) : false
        )
      } catch { /* editor not ready yet */ }
    }

    let rafId = null
    function onSelectionOrDoc() {
      if (rafId) cancelAnimationFrame(rafId)
      rafId = requestAnimationFrame(updateActiveStates)
    }

    // Listen for ProseMirror transactions to update toolbar state
    let transactionCleanup = null
    const checkEditorReady = setInterval(() => {
      const editor = getEditor()
      if (!editor) return
      clearInterval(checkEditorReady)
      try {
        const view = editor.action((ctx) => ctx.get(editorViewCtx))
        const origDispatch = view.dispatch.bind(view)
        view.dispatch = (tr) => {
          origDispatch(tr)
          if (tr.selectionSet || tr.docChanged) onSelectionOrDoc()
        }
        transactionCleanup = () => { view.dispatch = origDispatch }
        updateActiveStates()
      } catch { /* wait for next tick */ }
    }, 200)

    onMounted(() => {
      document.addEventListener('selectionchange', onSelectionOrDoc)
    })

    onUnmounted(() => {
      clearInterval(checkEditorReady)
      if (rafId) cancelAnimationFrame(rafId)
      document.removeEventListener('selectionchange', onSelectionOrDoc)
      if (transactionCleanup) transactionCleanup()
    })

    function execCommand(cmd, payload, toggleNode, toggleToText, markName) {
      const editor = getEditor()
      if (!editor) return

      // Inline mark toggle: use ProseMirror toggleMark directly
      if (markName) {
        try {
          const view = editor.action((ctx) => ctx.get(editorViewCtx))
          const markType = view.state.schema.marks[markName]
          if (markType) {
            toggleMark(markType)(view.state, view.dispatch, view)
            onSelectionOrDoc()
            return
          }
        } catch { /* mark not in schema */ }
      }

      // Toggle wrapper nodes: lift out of blockquote/list
      if (toggleNode) {
        try {
          const view = editor.action((ctx) => ctx.get(editorViewCtx))
          if (isNodeActive(view, toggleNode)) {
            lift(view.state, view.dispatch)
            onSelectionOrDoc()
            return
          }
        } catch { /* fall through to normal command */ }
      }
      // Toggle leaf block nodes: convert back to paragraph
      if (toggleToText && turnIntoTextCommand.key) {
        try {
          const view = editor.action((ctx) => ctx.get(editorViewCtx))
          if (isNodeActive(view, toggleToText)) {
            editor.action(callCommand(turnIntoTextCommand.key))
            onSelectionOrDoc()
            return
          }
        } catch { /* fall through to normal command */ }
      }
      if (!cmd.key) return
      editor.action(callCommand(cmd.key, payload))
      onSelectionOrDoc()
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
              class: [
                'story-editor__toolbar-btn',
                activeStates.value[i] ? 'story-editor__toolbar-btn--active' : '',
              ],
              title: btn.title,
              style: btn.style || null,
              disabled: loading.value,
              onMousedown: (e) => {
                e.preventDefault() // keep editor focus
                execCommand(btn.command, btn.payload, btn.toggle, btn.toggleToText, btn.markName)
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
