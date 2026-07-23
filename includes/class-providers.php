<?php
/**
 * AI provider presets and model catalogs.
 *
 * @package WCAI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provider metadata for settings + client routing.
 */
class WCAI_Providers {

	/**
	 * Supported provider IDs.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array( 'openai', 'claude', 'gemini', 'longcat', 'openrouter', 'custom' );
	}

	/**
	 * Provider definitions.
	 *
	 * @return array
	 */
	public static function all(): array {
		return array(
			'openai'     => array(
				'label'            => 'OpenAI',
				'api_base'         => 'https://api.openai.com/v1',
				'api_style'        => 'openai',
				'local_embeddings' => false,
				'chat_models'      => array(
					'gpt-4o-mini'       => 'GPT-4o Mini',
					'gpt-4o'            => 'GPT-4o',
					'gpt-4.1-mini'      => 'GPT-4.1 Mini',
					'gpt-4.1'           => 'GPT-4.1',
					'o4-mini'           => 'o4-mini',
				),
				'default_chat'     => 'gpt-4o-mini',
				'embedding_models' => array(
					'text-embedding-3-small' => 'text-embedding-3-small',
					'text-embedding-3-large' => 'text-embedding-3-large',
				),
				'default_embedding'=> 'text-embedding-3-small',
				'key_hint'         => 'sk-… from platform.openai.com',
			),
			'claude'     => array(
				'label'            => 'Claude (Anthropic)',
				'api_base'         => 'https://api.anthropic.com/v1',
				'api_style'        => 'anthropic',
				'local_embeddings' => true,
				'chat_models'      => array(
					'claude-sonnet-4-5'   => 'Claude Sonnet 4.5',
					'claude-opus-4-5'     => 'Claude Opus 4.5',
					'claude-haiku-4-5'    => 'Claude Haiku 4.5',
					'claude-3-5-sonnet-latest' => 'Claude 3.5 Sonnet (latest)',
					'claude-3-5-haiku-latest'  => 'Claude 3.5 Haiku (latest)',
				),
				'default_chat'     => 'claude-sonnet-4-5',
				'embedding_models' => array(),
				'default_embedding'=> '',
				'key_hint'         => 'sk-ant-… from console.anthropic.com',
			),
			'gemini'     => array(
				'label'            => 'Gemini (Google)',
				'api_base'         => 'https://generativelanguage.googleapis.com/v1beta/openai',
				'api_style'        => 'openai',
				'local_embeddings' => false,
				'chat_models'      => array(
					'gemini-2.5-flash'      => 'Gemini 2.5 Flash',
					'gemini-2.5-pro'        => 'Gemini 2.5 Pro',
					'gemini-2.0-flash'      => 'Gemini 2.0 Flash',
					'gemini-1.5-flash'      => 'Gemini 1.5 Flash',
					'gemini-1.5-pro'        => 'Gemini 1.5 Pro',
				),
				'default_chat'     => 'gemini-2.5-flash',
				'embedding_models' => array(
					'text-embedding-004' => 'text-embedding-004',
					'gemini-embedding-001' => 'gemini-embedding-001',
				),
				'default_embedding'=> 'text-embedding-004',
				'key_hint'         => 'AIza… from Google AI Studio',
			),
			'longcat'    => array(
				'label'            => 'LongCat Chat',
				'api_base'         => 'https://api.longcat.chat/openai/v1',
				'api_style'        => 'openai',
				'local_embeddings' => true,
				'chat_models'      => array(
					'LongCat-Flash-Chat'            => 'LongCat Flash Chat',
					'LongCat-2.0'                   => 'LongCat 2.0',
					'LongCat-Flash-Lite'            => 'LongCat Flash Lite',
					'LongCat-Flash-Thinking-2601'   => 'LongCat Flash Thinking',
				),
				'default_chat'     => 'LongCat-Flash-Chat',
				'embedding_models' => array(),
				'default_embedding'=> '',
				'key_hint'         => 'API key from longcat.chat/platform',
			),
			'openrouter' => array(
				'label'            => 'OpenRouter',
				'api_base'         => 'https://openrouter.ai/api/v1',
				'api_style'        => 'openai',
				'local_embeddings' => false,
				'chat_models'      => array(
					'openai/gpt-4o-mini'              => 'OpenAI GPT-4o Mini',
					'openai/gpt-4o'                   => 'OpenAI GPT-4o',
					'anthropic/claude-sonnet-4.5'     => 'Claude Sonnet 4.5',
					'anthropic/claude-3.5-sonnet'     => 'Claude 3.5 Sonnet',
					'google/gemini-2.5-flash'         => 'Gemini 2.5 Flash',
					'google/gemini-2.0-flash-001'     => 'Gemini 2.0 Flash',
					'meta-llama/llama-3.3-70b-instruct' => 'Llama 3.3 70B',
					'deepseek/deepseek-chat'          => 'DeepSeek Chat',
				),
				'default_chat'     => 'openai/gpt-4o-mini',
				'embedding_models' => array(
					'openai/text-embedding-3-small' => 'OpenAI text-embedding-3-small',
				),
				'default_embedding'=> 'openai/text-embedding-3-small',
				'key_hint'         => 'sk-or-… from openrouter.ai',
			),
			'custom'     => array(
				'label'            => 'Custom (OpenAI-compatible)',
				'api_base'         => '',
				'api_style'        => 'openai',
				'local_embeddings' => false,
				'chat_models'      => array(),
				'default_chat'     => '',
				'embedding_models' => array(),
				'default_embedding'=> '',
				'key_hint'         => 'Your provider API key',
			),
		);
	}

	/**
	 * One provider config.
	 *
	 * @param string $id Provider ID.
	 * @return array
	 */
	public static function get( string $id ): array {
		$all = self::all();
		return $all[ $id ] ?? $all['openai'];
	}

	/**
	 * Default API base for a provider.
	 *
	 * @param string $id Provider.
	 * @return string
	 */
	public static function default_base( string $id ): string {
		return (string) ( self::get( $id )['api_base'] ?? '' );
	}

	/**
	 * Whether auto mode should use local embeddings.
	 *
	 * @param string $id Provider.
	 * @return bool
	 */
	public static function prefers_local_embeddings( string $id ): bool {
		return ! empty( self::get( $id )['local_embeddings'] );
	}
}
