<script>
/**
 * StoryEditor — Milkdown WYSIWYG Markdown editor for story content.
 *
 * Uses @milkdown/vue with commonmark preset and listener plugin
 * to provide a rich editing experience that stores Markdown natively.
 *
 * Structure: outer component renders MilkdownProvider, inner component
 * calls useEditor (which requires inject from MilkdownProvider parent).
 */
import { defineComponent, h, watch } from 'vue'
import { Editor, defaultValueCtx } from '@milkdown/core'
import { commonmark } from '@milkdown/preset-commonmark'
import { listener, listenerCtx } from '@milkdown/plugin-listener'
import { history } from '@milkdown/plugin-history'
import { nord } from '@milkdown/theme-nord'
import { Milkdown, MilkdownProvider, useEditor, useInstance } from '@milkdown/vue'
import { replaceAll } from '@milkdown/utils'

import '@milkdown/theme-nord/style.css'

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
    let isExternalUpdate = false

    useEditor((root) => {
      return Editor.make()
        .config(nord)
        .config((ctx) => {
          ctx.set(defaultValueCtx, props.content || '')
          ctx.get(listenerCtx)
            .markdownUpdated((_ctx, markdown, prevMarkdown) => {
              if (isExternalUpdate) return
              if (markdown !== prevMarkdown) {
                emit('update:content', markdown)
              }
            })
        })
        .use(commonmark)
        .use(listener)
        .use(history)
    })

    const [loading, getEditor] = useInstance()

    // Watch for external content changes (e.g., story reload)
    watch(() => props.content, (newVal) => {
      if (loading.value) return
      const editor = getEditor()
      if (!editor) return
      isExternalUpdate = true
      editor.action(replaceAll(newVal || ''))
      isExternalUpdate = false
    })

    return () => h('div', { class: 'story-editor' }, [
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
