// Package nexorasms is the official Go SDK for the NexoraSMS API: SMS, WhatsApp and email sending,
// contacts, wallet, campaigns and webhook verification.
//
// Create a client with NewClientBuilder, build requests with NewSendMessageRequest, NewContactRequest,
// NewMessageQuery and NewContactQuery, and call the resources with a context:
//
//	client, err := nexorasms.NewClientBuilder().
//		PublicKey(os.Getenv("NEXORA_SMS_PUBLIC_KEY")).
//		APIKey(os.Getenv("NEXORA_SMS_API_KEY")).
//		Build()
//	req, err := nexorasms.NewSendMessageRequest().SMS("Olá!").To("+258841234567").Build()
//	result, err := client.Messages.Send(ctx, req)
//
// API failures are returned as *APIError (see IsInsufficientFunds, IsRateLimited, ...); network failures
// and timeouts as *ConnectionError (errors.Is(err, ErrConnection)). Configuration and builder validation
// problems are plain errors and never reach the network.
package nexorasms
